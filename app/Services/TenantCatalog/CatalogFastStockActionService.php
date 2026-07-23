<?php

namespace App\Services\TenantCatalog;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantLocalStock;
use App\Models\TenantStockReservation;
use App\Models\TenantSupplierAccess;
use App\Models\TenantSupplierPurchaseEntry;
use App\Models\User;
use App\Services\CurrentAccountSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogFastStockActionService
{
    public const ENTRY_TYPE_OPENING_STOCK = 'existing_stock';
    public const ENTRY_TYPE_COMPLETED_PURCHASE = 'supplier_purchase';
    public const SOURCE_TYPE_PURCHASE_DEBIT = 'catalog_completed_purchase_entry';
    public const SOURCE_TYPE_PURCHASE_REVERSAL = 'catalog_completed_purchase_entry_reversal';

    public function __construct(
        private readonly CurrentAccountSyncService $currentAccountSyncService,
    ) {
    }

    public function store(
        TenantAccount $tenant,
        TenantCatalogProduct $product,
        ?TenantCatalogProductVariant $variant,
        array $validated,
        User $user,
    ): TenantSupplierPurchaseEntry {
        $this->assertVariantScope($product, $variant);

        return DB::transaction(function () use ($tenant, $product, $variant, $validated, $user): TenantSupplierPurchaseEntry {
            $entryType = (string) $validated['entry_type'];
            $quantity = round((float) $validated['quantity'], 4);
            $snapshot = $this->priceSnapshot($product, $variant, $validated, $entryType);
            $sourceSummary = $this->resolvePrimarySourceSummary($product, $variant);
            $supplier = $this->resolveSupplier($tenant, $product, $variant, $entryType);

            if ($entryType === self::ENTRY_TYPE_COMPLETED_PURCHASE) {
                if (! $supplier) {
                    throw ValidationException::withMessages([
                        'entry_type' => 'Satın alma kaydedilemedi. Tedarikçi cari kartı bağlantısı eksik.',
                    ]);
                }

                if ((float) data_get($snapshot, 'purchase_total_try', 0) <= 0) {
                    throw ValidationException::withMessages([
                        'entry_type' => 'Satın alma kaydedilemedi. Alış toplamı 0\'dan büyük olmalıdır.',
                    ]);
                }
            }

            $stock = $this->upsertStockRow($tenant, $product, $variant, $validated);

            $idempotencyKey = $this->resolveIdempotencyKey($product, $variant, $validated);
            $existing = TenantSupplierPurchaseEntry::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing;
            }

            $entry = TenantSupplierPurchaseEntry::query()->create([
                'tenant_account_id' => $tenant->id,
                'supplier_id' => $supplier?->id,
                'supplier_source_id' => $this->extractSupplierSourceIdFromSourceSummary($sourceSummary),
                'tenant_catalog_product_id' => $product->id,
                'tenant_catalog_product_variant_id' => $variant?->id,
                'stock_scope' => $variant ? 'variant' : 'product',
                'supplier_name' => $supplier?->name,
                'product_code' => $variant?->variant_code ?: $product->display_code,
                'product_name' => $variant?->display_name ?: $product->display_name,
                'quantity' => $quantity,
                'list_price' => $snapshot['original_list_price'],
                'discount_rate' => $snapshot['discount_rate'],
                'calculated_purchase_unit_price' => $snapshot['calculated_unit_price_original'],
                'unit_purchase_price' => $snapshot['final_unit_price_original'],
                'manual_purchase_unit_price' => $snapshot['manual_override'],
                'currency' => $snapshot['original_currency'],
                'original_currency' => $snapshot['original_currency'],
                'exchange_rate' => $snapshot['exchange_rate'],
                'exchange_rate_date' => $snapshot['exchange_rate_date'],
                'original_list_price' => $snapshot['original_list_price'],
                'calculated_unit_price_original' => $snapshot['calculated_unit_price_original'],
                'final_unit_price_original' => $snapshot['final_unit_price_original'],
                'final_unit_price_try' => $snapshot['final_unit_price_try'],
                'purchase_total_try' => $snapshot['purchase_total_try'],
                'manual_override' => $snapshot['manual_override'],
                'vat_enabled' => false,
                'vat_rate' => 0,
                'total_amount' => $snapshot['purchase_total_try'],
                'payable_amount' => $entryType === self::ENTRY_TYPE_COMPLETED_PURCHASE ? $snapshot['purchase_total_try'] : 0,
                'entry_type' => $entryType,
                'entry_status' => 'completed',
                'payable_status' => $entryType === self::ENTRY_TYPE_COMPLETED_PURCHASE ? 'open' : 'none',
                'idempotency_key' => $idempotencyKey,
                'document_no' => $validated['document_no'] ?? null,
                'entry_date' => $validated['entry_date'] ?? now()->toDateString(),
                'warehouse_code' => $stock->warehouse_code,
                'location_code' => $stock->location_code,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $user->id,
                'meta_json' => [
                    'entry_mode' => $entryType,
                    'stock_action' => $entryType === self::ENTRY_TYPE_COMPLETED_PURCHASE ? 'completed_purchase' : 'opening_stock',
                    'movement_reason' => $entryType === self::ENTRY_TYPE_COMPLETED_PURCHASE ? 'purchase' : 'adjustment',
                    'price_snapshot' => $snapshot,
                ],
            ]);

            $this->incrementOperationalStock($stock, $quantity);
            $this->syncProjectionStock($product, $variant, $stock);
            $this->createMovement($tenant, $product, $stock, $entry, $snapshot, $quantity, 'in', $entryType === self::ENTRY_TYPE_COMPLETED_PURCHASE ? 'purchase' : 'adjustment', $user);

            if ($entryType === self::ENTRY_TYPE_COMPLETED_PURCHASE) {
                $debit = $this->createSupplierDebit($tenant, $supplier, $entry, $snapshot, $user);

                if (! $debit) {
                    throw ValidationException::withMessages([
                        'entry_type' => 'Satın alma kaydedilemedi. Tedarikçi cari kartı bağlantısı eksik.',
                    ]);
                }
            }

            return $entry;
        });
    }

    public function cancel(TenantAccount $tenant, TenantSupplierPurchaseEntry $entry, string $reason, User $user): TenantSupplierPurchaseEntry
    {
        if ((int) $entry->tenant_account_id !== (int) $tenant->id) {
            abort(403);
        }

        if ($entry->cancelled_at !== null || $entry->entry_status === 'cancelled') {
            return $entry;
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'cancellation_reason' => 'İptal nedeni zorunludur.',
            ]);
        }

        return DB::transaction(function () use ($tenant, $entry, $reason, $user): TenantSupplierPurchaseEntry {
            $product = TenantCatalogProduct::query()->findOrFail($entry->tenant_catalog_product_id);
            $variant = $entry->tenant_catalog_product_variant_id
                ? TenantCatalogProductVariant::query()->findOrFail($entry->tenant_catalog_product_variant_id)
                : null;
            $stock = $this->findStockRowForEntry($tenant, $entry);
            $quantity = round((float) $entry->quantity, 4);

            if ((float) $stock->quantity_available < $quantity || (float) $stock->quantity_on_hand < $quantity) {
                throw ValidationException::withMessages([
                    'cancellation_reason' => 'Stok kullanıldığı veya rezerve edildiği için işlem iptal edilemiyor.',
                ]);
            }

            $activeReservations = TenantStockReservation::query()
                ->where('tenant_local_stock_id', $stock->id)
                ->whereIn('status', ['active', 'reserved'])
                ->count();

            if ($activeReservations > 0) {
                throw ValidationException::withMessages([
                    'cancellation_reason' => 'Stok kullanıldığı veya rezerve edildiği için işlem iptal edilemiyor.',
                ]);
            }

            $this->decrementOperationalStock($stock, $quantity);
            $this->syncProjectionStock($product, $variant, $stock);

            $snapshot = (array) data_get($entry->meta_json, 'price_snapshot', []);

            $this->createMovement(
                $tenant,
                $product,
                $stock,
                $entry,
                $snapshot,
                $quantity,
                'out',
                $entry->entry_type === self::ENTRY_TYPE_COMPLETED_PURCHASE ? 'adjustment' : 'adjustment',
                $user
            );

            if ($entry->entry_type === self::ENTRY_TYPE_COMPLETED_PURCHASE) {
                $this->createSupplierReversal($tenant, $entry, $snapshot, $reason, $user);
            }

            $entry->forceFill([
                'entry_status' => 'cancelled',
                'payable_status' => $entry->entry_type === self::ENTRY_TYPE_COMPLETED_PURCHASE ? 'cancelled' : 'none',
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancellation_reason' => $reason,
            ])->save();

            return $entry->fresh();
        });
    }

    private function priceSnapshot(TenantCatalogProduct $product, ?TenantCatalogProductVariant $variant, array $validated, string $entryType): array
    {
        if ($entryType === self::ENTRY_TYPE_OPENING_STOCK) {
            return [
                'original_currency' => 'TRY',
                'original_list_price' => 0.0,
                'discount_rate' => 0.0,
                'calculated_unit_price_original' => 0.0,
                'final_unit_price_original' => 0.0,
                'exchange_rate' => 1.0,
                'exchange_rate_date' => $validated['exchange_rate_date'] ?? now()->toDateString(),
                'final_unit_price_try' => 0.0,
                'purchase_total_try' => 0.0,
                'manual_override' => false,
            ];
        }

        $priceMeta = (array) data_get($variant?->meta ?: $product->meta, 'price_snapshot', []);
        $originalCurrency = $this->normalizeCurrency((string) ($validated['currency'] ?? $variant?->currency ?? $product->currency ?? 'TRY'));
        $originalListPrice = round((float) ($validated['list_price'] ?? data_get($priceMeta, 'list_price', $variant?->display_price ?? $product->display_price ?? 0)), 4);
        $discountRate = round((float) ($validated['discount_rate'] ?? data_get($priceMeta, 'discount_rate', 0)), 4);
        $calculatedUnitOriginal = round($originalListPrice * (1 - ($discountRate / 100)), 4);
        $manualOverride = (bool) ($validated['manual_purchase_unit_price'] ?? false);
        $finalUnitOriginal = round((float) ($manualOverride ? ($validated['unit_purchase_price'] ?? $calculatedUnitOriginal) : ($validated['calculated_purchase_unit_price'] ?? $calculatedUnitOriginal)), 4);
        $exchangeRate = $originalCurrency === 'TRY'
            ? 1.0
            : round((float) ($validated['exchange_rate'] ?? data_get($priceMeta, 'exchange_rate', 0)), 6);

        if ($exchangeRate <= 0) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'Kur 0’dan büyük olmalıdır.',
            ]);
        }

        $finalUnitTry = round($finalUnitOriginal * $exchangeRate, 4);
        $quantity = round((float) ($validated['quantity'] ?? 0), 4);

        return [
            'original_currency' => $originalCurrency,
            'original_list_price' => $originalListPrice,
            'discount_rate' => $discountRate,
            'calculated_unit_price_original' => $calculatedUnitOriginal,
            'final_unit_price_original' => $finalUnitOriginal,
            'exchange_rate' => $exchangeRate,
            'exchange_rate_date' => $validated['exchange_rate_date'] ?? now()->toDateString(),
            'final_unit_price_try' => $finalUnitTry,
            'purchase_total_try' => round($finalUnitTry * $quantity, 4),
            'manual_override' => $manualOverride,
        ];
    }

    private function resolveSupplier(
        TenantAccount $tenant,
        TenantCatalogProduct $product,
        ?TenantCatalogProductVariant $variant,
        string $entryType,
    ): ?Supplier {
        $supplierId = $this->extractSupplierIdFromSourceSummary($this->resolvePrimarySourceSummary($product, $variant));

        if (! $supplierId) {
            return null;
        }

        $access = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('supplier_id', $supplierId)
            ->where('is_active', true)
            ->first();

        if (! $access || ! $access->can_view_products || ($entryType === self::ENTRY_TYPE_COMPLETED_PURCHASE && ! $access->can_request_purchase)) {
            abort(403, 'Bu tedarikçi için işlem izniniz yok.');
        }

        return Supplier::query()->find($supplierId);
    }

    private function resolveIdempotencyKey(TenantCatalogProduct $product, ?TenantCatalogProductVariant $variant, array $validated): string
    {
        $provided = trim((string) ($validated['idempotency_key'] ?? ''));
        if ($provided !== '') {
            return $provided;
        }

        return sha1(implode('|', [
            $validated['entry_type'],
            $product->id,
            $variant?->id ?: 'flat',
            round((float) $validated['quantity'], 4),
            trim((string) ($validated['document_no'] ?? '')),
            trim((string) ($validated['entry_date'] ?? now()->toDateString())),
            round((float) ($validated['unit_purchase_price'] ?? $validated['calculated_purchase_unit_price'] ?? 0), 4),
        ]));
    }

    private function upsertStockRow(TenantAccount $tenant, TenantCatalogProduct $product, ?TenantCatalogProductVariant $variant, array $validated): TenantLocalStock
    {
        return TenantLocalStock::query()->firstOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'tenant_catalog_product_id' => $product->id,
                'tenant_catalog_product_variant_id' => $variant?->id,
                'warehouse_code' => $validated['warehouse_code'] ?? 'LOCAL-MAIN',
                'location_code' => $validated['location_code'] ?? null,
            ],
            [
                'stock_scope' => $variant ? 'variant' : 'product',
                'legacy_assignment_status' => null,
                'quantity_on_hand' => 0,
                'quantity_reserved' => 0,
                'quantity_available' => 0,
                'reorder_level' => 0,
                'max_stock' => null,
                'last_counted_at' => now(),
                'notes' => 'Katalog stok işlemi için oluşturuldu.',
            ]
        );
    }

    private function incrementOperationalStock(TenantLocalStock $stock, float $quantity): void
    {
        $stock->quantity_on_hand = round((float) $stock->quantity_on_hand + $quantity, 4);
        $stock->quantity_available = round(max((float) $stock->quantity_on_hand - (float) $stock->quantity_reserved, 0), 4);
        $stock->last_counted_at = now();
        $stock->save();
    }

    private function decrementOperationalStock(TenantLocalStock $stock, float $quantity): void
    {
        $stock->quantity_on_hand = round((float) $stock->quantity_on_hand - $quantity, 4);
        $stock->quantity_available = round(max((float) $stock->quantity_on_hand - (float) $stock->quantity_reserved, 0), 4);
        $stock->last_counted_at = now();
        $stock->save();
    }

    private function syncProjectionStock(TenantCatalogProduct $product, ?TenantCatalogProductVariant $variant, TenantLocalStock $stock): void
    {
        $operationalLocal = TenantLocalStock::query()
            ->where('tenant_account_id', $product->tenant_account_id)
            ->where('tenant_catalog_product_id', $product->id)
            ->sum('quantity_on_hand');

        if ($variant) {
            $variantOperational = TenantLocalStock::query()
                ->where('tenant_account_id', $product->tenant_account_id)
                ->where('tenant_catalog_product_id', $product->id)
                ->where('tenant_catalog_product_variant_id', $variant->id)
                ->sum('quantity_on_hand');
            $variant->forceFill([
                'local_stock_quantity' => $variantOperational,
                'stock_quantity' => round((float) $variantOperational + (float) ($variant->supplier_stock_quantity ?? 0), 4),
            ])->save();
        }

        $product->forceFill([
            'local_stock_quantity' => $operationalLocal,
            'total_stock_quantity' => round((float) $operationalLocal + (float) ($product->supplier_stock_quantity ?? 0), 4),
            'stock_quantity' => (int) round((float) $operationalLocal + (float) ($product->supplier_stock_quantity ?? 0)),
            'local_stock_priority' => (float) $operationalLocal > 0,
        ])->save();
    }

    private function createMovement(
        TenantAccount $tenant,
        TenantCatalogProduct $product,
        TenantLocalStock $stock,
        TenantSupplierPurchaseEntry $entry,
        array $snapshot,
        float $quantity,
        string $movementType,
        string $reason,
        User $user,
    ): void {
        StockMovement::query()->create([
            'tenant_account_id' => $tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'tenant_local_stock_id' => $stock->id,
            'movement_type' => $movementType,
            'reason' => $reason,
            'quantity' => $quantity,
            'reference_type' => TenantSupplierPurchaseEntry::class,
            'reference_id' => $entry->id,
            'reference_document' => $entry->document_no,
            'unit_cost' => data_get($snapshot, 'final_unit_price_try'),
            'currency' => 'TRY',
            'warehouse_code' => $stock->warehouse_code,
            'location_code' => $stock->location_code,
            'notes' => $reason,
            'moved_by' => $user->id,
            'moved_at' => now(),
            'created_by' => $user->id,
        ]);
    }

    private function createSupplierDebit(
        TenantAccount $tenant,
        ?Supplier $supplier,
        TenantSupplierPurchaseEntry $entry,
        array $snapshot,
        User $user,
    ): ?CurrentAccountTransaction {
        if (! $supplier) {
            return null;
        }

        $existing = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('source_type', self::SOURCE_TYPE_PURCHASE_DEBIT)
            ->where('source_id', $entry->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $account = $this->resolveSupplierCurrentAccount($tenant, $supplier);

        return CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            'source_type' => self::SOURCE_TYPE_PURCHASE_DEBIT,
            'source_id' => $entry->id,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => round((float) data_get($snapshot, 'purchase_total_try', 0), 2),
            'currency' => 'TRY',
            'transaction_date' => $entry->entry_date ?? now()->toDateString(),
            'due_date' => null,
            'description' => 'Tamamlanmış satın alma: ' . ($entry->product_name ?: $entry->product_code),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'created_by' => $user->id,
            'meta_json' => [
                'created_via' => 'catalog_completed_purchase',
                'purchase_entry_id' => $entry->id,
                'document_no' => $entry->document_no,
            ],
        ]);
    }

    private function createSupplierReversal(
        TenantAccount $tenant,
        TenantSupplierPurchaseEntry $entry,
        array $snapshot,
        string $reason,
        User $user,
    ): ?CurrentAccountTransaction {
        $debit = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('source_type', self::SOURCE_TYPE_PURCHASE_DEBIT)
            ->where('source_id', $entry->id)
            ->first();

        if (! $debit) {
            return null;
        }

        $existing = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('source_type', self::SOURCE_TYPE_PURCHASE_REVERSAL)
            ->where('source_id', $entry->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $debit->current_account_id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_PAYMENT,
            'source_type' => self::SOURCE_TYPE_PURCHASE_REVERSAL,
            'source_id' => $entry->id,
            'direction' => CurrentAccountTransaction::DIRECTION_CREDIT,
            'amount' => round((float) data_get($snapshot, 'purchase_total_try', $entry->payable_amount), 2),
            'currency' => 'TRY',
            'transaction_date' => now()->toDateString(),
            'due_date' => null,
            'description' => 'Tamamlanmış satın alma iptali: ' . ($entry->product_name ?: $entry->product_code),
            'status' => CurrentAccountTransaction::STATUS_CLOSED,
            'created_by' => $user->id,
            'meta_json' => [
                'created_via' => 'catalog_completed_purchase_cancellation',
                'reversal_reason' => 'adjustment',
                'purchase_entry_id' => $entry->id,
                'cancellation_reason' => $reason,
            ],
        ]);
    }

    private function findStockRowForEntry(TenantAccount $tenant, TenantSupplierPurchaseEntry $entry): TenantLocalStock
    {
        return TenantLocalStock::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('tenant_catalog_product_id', $entry->tenant_catalog_product_id)
            ->where('tenant_catalog_product_variant_id', $entry->tenant_catalog_product_variant_id)
            ->where('warehouse_code', $entry->warehouse_code ?: 'LOCAL-MAIN')
            ->where('location_code', $entry->location_code)
            ->firstOrFail();
    }

    private function assertVariantScope(TenantCatalogProduct $product, ?TenantCatalogProductVariant $variant): void
    {
        $hasActiveVariants = $product->variants()->where('is_active', true)->where('visible_in_catalog', true)->exists();

        if ($hasActiveVariants && ! $variant) {
            throw ValidationException::withMessages([
                'tenant_catalog_product_variant_id' => 'İşlem için ürün varyantı seçilmelidir.',
            ]);
        }
    }

    private function normalizeCurrency(string $currency): string
    {
        return match (strtoupper(trim($currency))) {
            'TL', 'TRY' => 'TRY',
            'USD' => 'USD',
            'EUR' => 'EUR',
            default => throw ValidationException::withMessages([
                'currency' => 'Geçerli para birimi seçin.',
            ]),
        };
    }

    private function resolvePrimarySourceSummary(TenantCatalogProduct $product, ?TenantCatalogProductVariant $variant): array
    {
        return $this->extractPrimarySourceSummary($this->normalizeSourceSummary($variant?->source_summary ?: $product->source_summary));
    }

    private function normalizeSourceSummary(mixed $sourceSummary): array
    {
        if (is_array($sourceSummary)) {
            return $sourceSummary;
        }

        if (! is_string($sourceSummary) || trim($sourceSummary) === '') {
            return [];
        }

        $decoded = json_decode($sourceSummary, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function extractPrimarySourceSummary(array $sourceSummary): array
    {
        if ($sourceSummary === []) {
            return [];
        }

        if (array_is_list($sourceSummary)) {
            foreach ($sourceSummary as $row) {
                if (is_array($row) && filled(data_get($row, 'supplier_id'))) {
                    return $row;
                }
            }

            return is_array($sourceSummary[0] ?? null) ? $sourceSummary[0] : [];
        }

        return $sourceSummary;
    }

    private function extractSupplierIdFromSourceSummary(array $sourceSummary): ?int
    {
        $supplierId = (int) data_get($sourceSummary, 'supplier_id', 0);

        return $supplierId > 0 ? $supplierId : null;
    }

    private function extractSupplierSourceIdFromSourceSummary(array $sourceSummary): ?int
    {
        $supplierSourceId = (int) data_get($sourceSummary, 'supplier_source_id', 0);

        return $supplierSourceId > 0 ? $supplierSourceId : null;
    }

    private function resolveSupplierCurrentAccount(TenantAccount $tenant, Supplier $supplier): CurrentAccount
    {
        $existing = CurrentAccount::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereHas('links', function ($query) use ($supplier) {
                $query->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
                    ->where('link_id', $supplier->id);
            })
            ->first();

        if ($existing) {
            $this->currentAccountSyncService->ensureRole($existing, CurrentAccountRole::ROLE_SUPPLIER);

            return $existing->fresh(['roles', 'links']);
        }

        try {
            return $this->currentAccountSyncService->ensureForSupplier($supplier, $tenant);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'entry_type' => 'Satın alma kaydedilemedi. Tedarikçi cari kartı bağlantısı eksik.',
            ]);
        }
    }
}
