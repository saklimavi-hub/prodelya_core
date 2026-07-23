<?php

namespace App\Services\Stock;

use App\Models\CurrentAccountTransaction;
use App\Models\NotificationLog;
use App\Models\StockMovement;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantLocalStock;
use App\Models\TenantStockReservation;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class LocalStockExactVariantCorrectionService
{
    public const LEGACY_STATUS_RESOLVED = 'resolved_exact_variant';

    public function dryRun(array $payload): array
    {
        $normalized = $this->normalizePayload($payload);

        return $this->inspectCorrection($normalized, false);
    }

    public function apply(array $payload): array
    {
        $normalized = $this->normalizePayload($payload);

        return DB::transaction(function () use ($normalized): array {
            $plan = $this->inspectCorrection($normalized, true);

            if ($plan['status'] === 'blocked' || $plan['status'] === 'already_corrected') {
                return $plan;
            }

            $writtenRows = [];

            foreach ($plan['variant_rows'] as $variantRowPlan) {
                $row = $variantRowPlan['existing_row']
                    ?? new TenantLocalStock([
                        'tenant_account_id' => $normalized['tenant_id'],
                        'tenant_catalog_product_id' => $normalized['product_id'],
                        'tenant_catalog_product_variant_id' => $variantRowPlan['variant_id'],
                        'warehouse_code' => $plan['legacy_row']['warehouse_code'],
                        'location_code' => $plan['legacy_row']['location_code'],
                    ]);

                $row->fill([
                    'tenant_account_id' => $normalized['tenant_id'],
                    'tenant_catalog_product_id' => $normalized['product_id'],
                    'tenant_catalog_product_variant_id' => $variantRowPlan['variant_id'],
                    'stock_scope' => 'variant',
                    'legacy_assignment_status' => null,
                    'warehouse_code' => $plan['legacy_row']['warehouse_code'],
                    'location_code' => $plan['legacy_row']['location_code'],
                    'quantity_on_hand' => $variantRowPlan['quantity'],
                    'quantity_reserved' => 0,
                    'quantity_available' => $variantRowPlan['quantity'],
                    'reorder_level' => 0,
                    'max_stock' => null,
                    'last_counted_at' => now(),
                    'notes' => $this->mergeNotes(
                        $row->notes,
                        sprintf(
                            'Exact varyant kimlik duzeltmesi uygulandi. legacy_stock_id=%d evidence_ids=%s',
                            $normalized['legacy_stock_id'],
                            implode(',', $normalized['evidence_ids'])
                        )
                    ),
                ]);
                $row->save();

                $writtenRows[] = $row->fresh();
            }

            $legacyRow = TenantLocalStock::query()->whereKey($normalized['legacy_stock_id'])->lockForUpdate()->firstOrFail();
            $legacyRow->fill([
                'stock_scope' => 'product',
                'legacy_assignment_status' => self::LEGACY_STATUS_RESOLVED,
                'quantity_on_hand' => 0,
                'quantity_reserved' => 0,
                'quantity_available' => 0,
                'last_counted_at' => now(),
                'notes' => $this->mergeNotes(
                    $legacyRow->notes,
                    sprintf(
                        'Exact varyant kimlik duzeltmesi tamamlandi. variant_map=%s evidence_ids=%s actor=%s',
                        collect($normalized['maps'])->map(fn (array $map) => $map['variant_id'] . ':' . $map['quantity'])->implode(','),
                        implode(',', $normalized['evidence_ids']),
                        $normalized['actor_id'] ? ('user:' . $normalized['actor_id']) : 'cli'
                    )
                ),
            ]);
            $legacyRow->save();

            $after = $this->inspectCorrection($normalized, true);
            $after['status'] = 'applied';
            $after['writes'] = 1 + count($writtenRows);
            $after['written_variant_row_ids'] = collect($writtenRows)->pluck('id')->all();
            $after['legacy_row_id'] = $legacyRow->id;

            return $after;
        });
    }

    private function normalizePayload(array $payload): array
    {
        $tenantId = (int) ($payload['tenant_id'] ?? $payload['tenant'] ?? 0);
        $productId = (int) ($payload['product_id'] ?? $payload['product'] ?? 0);
        $legacyStockId = (int) ($payload['legacy_stock_id'] ?? $payload['legacy_stock'] ?? 0);
        $actorId = Arr::get($payload, 'actor_id');
        $maps = collect($payload['maps'] ?? [])->map(function ($map): array {
            if (is_string($map)) {
                [$variantId, $quantity] = array_pad(explode(':', $map, 2), 2, null);

                return [
                    'variant_id' => (int) $variantId,
                    'quantity' => round((float) $quantity, 4),
                ];
            }

            return [
                'variant_id' => (int) Arr::get($map, 'variant_id'),
                'quantity' => round((float) Arr::get($map, 'quantity'), 4),
            ];
        })->values();

        if ($tenantId <= 0 || $productId <= 0 || $legacyStockId <= 0 || $maps->isEmpty()) {
            throw new InvalidArgumentException('tenant, product, legacy-stock ve en az bir map zorunludur.');
        }

        if ($maps->contains(fn (array $map) => $map['variant_id'] <= 0 || $map['quantity'] <= 0)) {
            throw new InvalidArgumentException('Map degerleri variant_id:quantity formatinda ve sifirdan buyuk olmalidir.');
        }

        if ($maps->pluck('variant_id')->duplicates()->isNotEmpty()) {
            throw new InvalidArgumentException('Ayni variant icin duplicate map girilemez.');
        }

        return [
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'legacy_stock_id' => $legacyStockId,
            'actor_id' => is_numeric($actorId) ? (int) $actorId : null,
            'maps' => $maps->all(),
            'evidence_ids' => collect($payload['evidence_ids'] ?? [1, 2])->map(fn ($id) => (int) $id)->filter()->values()->all(),
        ];
    }

    private function inspectCorrection(array $payload, bool $forUpdate): array
    {
        $productQuery = TenantCatalogProduct::query()->whereKey($payload['product_id'])->where('tenant_account_id', $payload['tenant_id']);
        $legacyQuery = TenantLocalStock::query()->whereKey($payload['legacy_stock_id'])->where('tenant_account_id', $payload['tenant_id']);

        if ($forUpdate) {
            $productQuery->lockForUpdate();
            $legacyQuery->lockForUpdate();
        }

        $product = $productQuery->first();
        $legacyRow = $legacyQuery->first();

        $sideEffectsBefore = $this->sideEffectCounts($payload['tenant_id'], $payload['product_id']);

        $guards = [];
        $variantRows = [];
        $remainingLegacy = [];

        if (! $product instanceof TenantCatalogProduct) {
            return $this->blockedResult('Product bulunamadi.', $guards, $sideEffectsBefore);
        }

        if (! $legacyRow instanceof TenantLocalStock) {
            return $this->blockedResult('Legacy local stock row bulunamadi.', $guards, $sideEffectsBefore);
        }

        $guard = fn (string $key, bool $passed, string $message, mixed $actual = null, mixed $expected = null) => [
            'key' => $key,
            'passed' => $passed,
            'message' => $message,
            'actual' => $actual,
            'expected' => $expected,
        ];

        $guards[] = $guard(
            'legacy_row_binding',
            (int) $legacyRow->tenant_catalog_product_id === (int) $product->id,
            'Legacy row selected producte bagli olmali.',
            (int) $legacyRow->tenant_catalog_product_id,
            (int) $product->id
        );
        $guards[] = $guard(
            'legacy_row_scope',
            $legacyRow->tenant_catalog_product_variant_id === null,
            'Legacy row exact variant bagina sahip olmamali.',
            $legacyRow->tenant_catalog_product_variant_id,
            null
        );
        $guards[] = $guard(
            'legacy_row_on_hand',
            round((float) $legacyRow->quantity_on_hand, 4) === 2000.0,
            'Legacy row on_hand 2000 olmali.',
            round((float) $legacyRow->quantity_on_hand, 4),
            2000.0
        );
        $guards[] = $guard(
            'legacy_row_reserved',
            round((float) $legacyRow->quantity_reserved, 4) === 0.0,
            'Legacy row reserved 0 olmali.',
            round((float) $legacyRow->quantity_reserved, 4),
            0.0
        );
        $guards[] = $guard(
            'legacy_row_available',
            round((float) $legacyRow->quantity_available, 4) === 2000.0,
            'Legacy row available 2000 olmali.',
            round((float) $legacyRow->quantity_available, 4),
            2000.0
        );

        $mappingTotal = round(collect($payload['maps'])->sum('quantity'), 4);
        $guards[] = $guard(
            'mapping_total',
            $mappingTotal === round((float) $legacyRow->quantity_on_hand, 4),
            'Map toplam quantity legacy on_hand ile ayni olmali.',
            $mappingTotal,
            round((float) $legacyRow->quantity_on_hand, 4)
        );

        $activeLegacyReservations = $this->activeReservationsForStockId($legacyRow->id, $forUpdate);
        $guards[] = $guard(
            'active_legacy_reservations',
            $activeLegacyReservations['active_count'] === 0,
            'Legacy row aktif reservation icermemeli.',
            $activeLegacyReservations['active_count'],
            0
        );

        $variantIds = collect($payload['maps'])->pluck('variant_id')->all();
        $variantsQuery = TenantCatalogProductVariant::query()
            ->whereIn('id', $variantIds)
            ->where('tenant_account_id', $payload['tenant_id']);

        if ($forUpdate) {
            $variantsQuery->lockForUpdate();
        }

        /** @var Collection<int, TenantCatalogProductVariant> $variants */
        $variants = $variantsQuery->get()->keyBy('id');

        foreach ($payload['maps'] as $map) {
            $variant = $variants->get($map['variant_id']);
            $variantExists = $variant instanceof TenantCatalogProductVariant;
            $variantBelongs = $variantExists && (int) $variant->tenant_catalog_product_id === (int) $product->id;

            $guards[] = $guard(
                'variant_' . $map['variant_id'] . '_binding',
                $variantBelongs,
                'Variant tenant ve product bagi dogru olmali.',
                $variantExists ? ['tenant_catalog_product_id' => $variant->tenant_catalog_product_id, 'tenant_account_id' => $variant->tenant_account_id] : null,
                ['tenant_catalog_product_id' => $product->id, 'tenant_account_id' => $payload['tenant_id']]
            );

            $exactRowsQuery = TenantLocalStock::query()
                ->where('tenant_account_id', $payload['tenant_id'])
                ->where('tenant_catalog_product_id', $payload['product_id'])
                ->where('tenant_catalog_product_variant_id', $map['variant_id']);

            if ($forUpdate) {
                $exactRowsQuery->lockForUpdate();
            }

            $exactRows = $exactRowsQuery->orderBy('id')->get();
            $guards[] = $guard(
                'variant_' . $map['variant_id'] . '_duplicate_rows',
                $exactRows->count() <= 1,
                'Exact variant row duplicate olmamali.',
                $exactRows->count(),
                0
            );

            $existingRow = $exactRows->first();
            $zeroSafe = true;
            $activeVariantReservations = ['active_count' => 0, 'active_quantity' => 0.0, 'released_count' => 0, 'consumed_count' => 0, 'cancelled_count' => 0];

            if ($existingRow instanceof TenantLocalStock) {
                $activeVariantReservations = $this->activeReservationsForStockId($existingRow->id, $forUpdate);
                $zeroSafe = round((float) $existingRow->quantity_on_hand, 4) === 0.0
                    && round((float) $existingRow->quantity_reserved, 4) === 0.0
                    && round((float) $existingRow->quantity_available, 4) === 0.0
                    && $activeVariantReservations['active_count'] === 0;
            }

            $guards[] = $guard(
                'variant_' . $map['variant_id'] . '_zero_safe',
                ! $existingRow || $zeroSafe,
                'Existing exact row absent veya zero-safe olmali.',
                $existingRow ? [
                    'on_hand' => round((float) $existingRow->quantity_on_hand, 4),
                    'reserved' => round((float) $existingRow->quantity_reserved, 4),
                    'available' => round((float) $existingRow->quantity_available, 4),
                    'active_reservations' => $activeVariantReservations['active_count'],
                ] : 'absent',
                'absent_or_zero_safe'
            );

            $variantRows[] = [
                'variant_id' => $map['variant_id'],
                'variant_code' => $variant?->variant_code,
                'variant_name' => $variant?->display_name,
                'quantity' => round((float) $map['quantity'], 4),
                'existing_row' => $existingRow,
                'existing_row_id' => $existingRow?->id,
                'existing_row_state' => $existingRow ? [
                    'on_hand' => round((float) $existingRow->quantity_on_hand, 4),
                    'reserved' => round((float) $existingRow->quantity_reserved, 4),
                    'available' => round((float) $existingRow->quantity_available, 4),
                    'warehouse_code' => $existingRow->warehouse_code,
                    'location_code' => $existingRow->location_code,
                ] : null,
                'reservation_state' => $activeVariantReservations,
            ];
        }

        $remainingLegacy = $this->remainingLegacySummary($payload['tenant_id'], $payload['product_id']);

        $alreadyCorrected = $this->matchesAlreadyCorrectedState($legacyRow, $variantRows);

        $result = [
            'status' => $alreadyCorrected ? 'already_corrected' : (collect($guards)->every(fn (array $guard) => $guard['passed']) ? 'dry_run' : 'blocked'),
            'mode' => 'dry_run',
            'writes' => 0,
            'tenant_id' => $payload['tenant_id'],
            'product_id' => $payload['product_id'],
            'legacy_stock_id' => $payload['legacy_stock_id'],
            'legacy_row' => [
                'id' => $legacyRow->id,
                'tenant_account_id' => $legacyRow->tenant_account_id,
                'tenant_catalog_product_id' => $legacyRow->tenant_catalog_product_id,
                'tenant_catalog_product_variant_id' => $legacyRow->tenant_catalog_product_variant_id,
                'stock_scope' => $legacyRow->stock_scope,
                'legacy_assignment_status' => $legacyRow->legacy_assignment_status,
                'quantity_on_hand' => round((float) $legacyRow->quantity_on_hand, 4),
                'quantity_reserved' => round((float) $legacyRow->quantity_reserved, 4),
                'quantity_available' => round((float) $legacyRow->quantity_available, 4),
                'warehouse_code' => $legacyRow->warehouse_code,
                'location_code' => $legacyRow->location_code,
                'notes' => $legacyRow->notes,
            ],
            'variant_rows' => $variantRows,
            'guards' => $guards,
            'totals' => [
                'before_operational' => round((float) $legacyRow->quantity_on_hand + collect($variantRows)->sum(fn (array $row) => (float) data_get($row, 'existing_row_state.on_hand', 0)), 4),
                'legacy_before' => round((float) $legacyRow->quantity_on_hand, 4),
                'mapped_total' => $mappingTotal,
                'after_operational_exact' => $mappingTotal,
                'double_count' => 0,
            ],
            'side_effects_before' => $sideEffectsBefore,
            'side_effects_after' => $sideEffectsBefore,
            'remaining_legacy' => $remainingLegacy,
            'historical_evidence_ids' => $payload['evidence_ids'],
        ];

        if ($alreadyCorrected) {
            $result['message'] = 'already_corrected';
        } elseif ($result['status'] === 'blocked') {
            $result['message'] = 'CONTROLLED CORRECTION BLOCKED';
        } else {
            $result['message'] = 'DRY-RUN READY';
        }

        return $result;
    }

    private function blockedResult(string $message, array $guards, array $sideEffectsBefore): array
    {
        return [
            'status' => 'blocked',
            'mode' => 'dry_run',
            'writes' => 0,
            'message' => $message,
            'guards' => $guards,
            'side_effects_before' => $sideEffectsBefore,
            'side_effects_after' => $sideEffectsBefore,
            'variant_rows' => [],
            'remaining_legacy' => [],
        ];
    }

    private function activeReservationsForStockId(int $stockId, bool $forUpdate): array
    {
        if (! Schema::hasTable('tenant_stock_reservations')) {
            return [
                'active_count' => 0,
                'active_quantity' => 0.0,
                'released_count' => 0,
                'consumed_count' => 0,
                'cancelled_count' => 0,
            ];
        }

        $query = TenantStockReservation::query()->where('tenant_local_stock_id', $stockId);

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $reservations = $query->get();

        return [
            'active_count' => $reservations->where('status', TenantStockReservation::STATUS_ACTIVE)->count(),
            'active_quantity' => round((float) $reservations->where('status', TenantStockReservation::STATUS_ACTIVE)->sum('quantity'), 4),
            'released_count' => $reservations->where('status', TenantStockReservation::STATUS_RELEASED)->count(),
            'consumed_count' => $reservations->where('status', TenantStockReservation::STATUS_CONSUMED)->count(),
            'cancelled_count' => $reservations->where('status', TenantStockReservation::STATUS_CANCELLED)->count(),
        ];
    }

    private function remainingLegacySummary(int $tenantId, int $excludedProductId): array
    {
        $rows = TenantLocalStock::query()
            ->with('catalogProduct.variants')
            ->where('tenant_account_id', $tenantId)
            ->whereNull('tenant_catalog_product_variant_id')
            ->where('quantity_on_hand', '>', 0)
            ->where('tenant_catalog_product_id', '!=', $excludedProductId)
            ->get()
            ->filter(fn (TenantLocalStock $row) => $row->catalogProduct && $row->catalogProduct->variants->isNotEmpty())
            ->values();

        return [
            'count' => $rows->count(),
            'quantity_on_hand' => round((float) $rows->sum(fn (TenantLocalStock $row) => (float) $row->quantity_on_hand), 4),
            'rows' => $rows->map(fn (TenantLocalStock $row) => [
                'stock_id' => $row->id,
                'product_id' => $row->tenant_catalog_product_id,
                'product_code' => $row->catalogProduct?->product_code,
                'product_name' => $row->catalogProduct?->display_name,
                'quantity_on_hand' => round((float) $row->quantity_on_hand, 4),
                'legacy_assignment_status' => $row->legacy_assignment_status,
            ])->all(),
        ];
    }

    private function matchesAlreadyCorrectedState(TenantLocalStock $legacyRow, array $variantRows): bool
    {
        if (round((float) $legacyRow->quantity_on_hand, 4) !== 0.0
            || round((float) $legacyRow->quantity_reserved, 4) !== 0.0
            || round((float) $legacyRow->quantity_available, 4) !== 0.0) {
            return false;
        }

        foreach ($variantRows as $row) {
            $existing = $row['existing_row'];

            if (! $existing instanceof TenantLocalStock) {
                return false;
            }

            if (round((float) $existing->quantity_on_hand, 4) !== round((float) $row['quantity'], 4)) {
                return false;
            }

            if (round((float) $existing->quantity_reserved, 4) !== 0.0 || round((float) $existing->quantity_available, 4) !== round((float) $row['quantity'], 4)) {
                return false;
            }
        }

        return true;
    }

    private function sideEffectCounts(int $tenantId, int $productId): array
    {
        return [
            'stock_movements' => Schema::hasTable('stock_movements')
                ? StockMovement::query()->where('tenant_account_id', $tenantId)->where('tenant_catalog_product_id', $productId)->count()
                : 0,
            'current_account_transactions' => Schema::hasTable('current_account_transactions')
                ? CurrentAccountTransaction::query()->where('tenant_account_id', $tenantId)->count()
                : 0,
            'notification_logs' => Schema::hasTable('notification_logs')
                ? NotificationLog::query()->where('tenant_account_id', $tenantId)->count()
                : 0,
            'order_item_procurements' => Schema::hasTable('order_item_procurements')
                ? DB::table('order_item_procurements')->where('tenant_account_id', $tenantId)->count()
                : 0,
            'supplier_procurement_request_items' => Schema::hasTable('supplier_procurement_request_items')
                ? DB::table('supplier_procurement_request_items')->count()
                : 0,
        ];
    }

    private function mergeNotes(?string $existing, string $addition): string
    {
        $base = trim((string) $existing);

        if ($base === '') {
            return $addition;
        }

        if (str_contains($base, $addition)) {
            return $base;
        }

        return $base . PHP_EOL . $addition;
    }
}
