<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderRevision;
use App\Models\OrderRevisionChange;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderRevisionApplyService
{
    public const ALREADY_APPLIED_MESSAGE = 'Bu revizyon daha önce uygulanmış.';
    public const NO_APPLICABLE_MESSAGE = 'Bu revizyonda otomatik uygulanabilir bir alan bulunamadı.';

    public function __construct(
        protected OrderRevisionComparisonService $comparisonService,
        protected OrderRevisionRecordService $recordService,
    ) {}

    public function apply(OrderRevision $revision, User $user, array $options = []): OrderRevision
    {
        return DB::transaction(function () use ($revision, $user): OrderRevision {
            $revision = OrderRevision::query()
                ->with([
                    'order.items.prints',
                    'order.procurements',
                    'order.printProductions',
                    'order.deliveries',
                    'order.payments',
                    'revisionQuote.items.prints',
                    'changes',
                ])
                ->lockForUpdate()
                ->findOrFail($revision->id);

            $this->guardRevision($revision, $user);

            $sourceOrder = $revision->order;
            $revisionQuote = $revision->revisionQuote;
            $comparison = $this->comparisonService->build($revisionQuote->fresh([
                'customer',
                'items.prints',
                'sourceOrder.customer',
                'sourceOrder.items.procurement',
                'sourceOrder.items.delivery',
                'sourceOrder.items.prints.production',
                'sourceOrder.procurements',
                'sourceOrder.printProductions',
                'sourceOrder.deliveries',
                'sourceOrder.payments',
            ]));

            $revision = $this->recordService->createOrUpdateFromComparison(
                $sourceOrder->fresh([
                    'items.prints',
                    'procurements',
                    'printProductions',
                    'deliveries',
                    'payments',
                ]),
                $revisionQuote->fresh(['items.prints', 'sourceOrder']),
                $comparison,
                $user
            );

            $decisionMap = collect($comparison['decisionMatrix'] ?? [])
                ->mapWithKeys(fn (array $row) => [$this->normalizeFieldKey((string) ($row['label'] ?? '')) => $row]);
            $itemMap = collect($comparison['lineComparisons'] ?? [])
                ->values()
                ->mapWithKeys(fn (array $row, int $index) => ['item_line_' . ($index + 1) => $row]);
            $printMap = collect($comparison['lineComparisons'] ?? [])
                ->values()
                ->flatMap(function (array $row, int $itemIndex): array {
                    return collect($row['prints'] ?? [])
                        ->values()
                        ->mapWithKeys(fn (array $print, int $printIndex) => [
                            'print_line_' . ($itemIndex + 1) . '_' . ($printIndex + 1) => $print,
                        ])
                        ->all();
                });

            $order = $revision->order()->with(['items.prints', 'procurements', 'printProductions', 'deliveries', 'payments'])->firstOrFail();
            $appliedAny = false;
            $decisionOutcomes = [];

            foreach ($revision->changes()->orderBy('id')->get() as $change) {
                $applied = match ($change->change_group) {
                    'item_line' => $this->applyItemLineChange($change, $itemMap->get($change->field_key), $decisionMap, $order),
                    'print_line' => $this->applyPrintLineChange($change, $printMap->get($change->field_key), $decisionMap, $order),
                    'decision_matrix' => null,
                    default => false,
                };

                if ($applied === true) {
                    $appliedAny = true;
                }

                if (in_array($change->change_group, ['item_line', 'print_line'], true)) {
                    $this->trackDecisionOutcome($decisionOutcomes, $change, $itemMap->get($change->field_key) ?? $printMap->get($change->field_key));
                }
            }

            $orderApplied = $this->applyOrderLevelChanges($revision, $decisionMap, $order);
            $appliedAny = $appliedAny || $orderApplied;
            if ($orderApplied) {
                $decisionOutcomes['teslim_bilgisi']['applied'] = true;
            }

            foreach ($revision->changes()->where('change_group', 'decision_matrix')->get() as $change) {
                $key = $change->field_key;
                $outcome = $decisionOutcomes[$key] ?? null;
                $this->resolveDecisionMatrixApplyStatus($change, $outcome);
            }

            if (! $appliedAny) {
                throw new DomainException(self::NO_APPLICABLE_MESSAGE);
            }

            $this->refreshOrderFinancialSnapshot($order->fresh(['items.prints']));

            $revision->forceFill([
                'status' => $this->resolveAppliedRevisionStatus($revision->changes()->get()),
                'applied_by_user_id' => $user->id,
                'applied_at' => now(),
            ])->save();

            AuditLog::log([
                'tenant_account_id' => $revision->tenant_account_id,
                'user_id' => $user->id,
                'action' => 'order_revision_applied',
                'entity_type' => OrderRevision::class,
                'entity_id' => $revision->id,
                'new_values' => [
                    'order_id' => $revision->order_id,
                    'revision_quote_id' => $revision->revision_quote_id,
                    'status' => $revision->status,
                    'applied_change_count' => $revision->changes()->where('apply_status', OrderRevisionChange::APPLY_STATUS_APPLIED)->count(),
                ],
                'notes' => 'Revizyon uygulanabilir ticari alanlara idempotent olarak işlendi.',
            ]);

            return $revision->fresh(['changes', 'order.items.prints', 'revisionQuote']);
        });
    }

    private function guardRevision(OrderRevision $revision, User $user): void
    {
        if ((int) $revision->tenant_account_id !== (int) $revision->order?->tenant_account_id) {
            throw new DomainException('Revizyon ve sipariş tenant bilgisi eşleşmiyor.');
        }

        if ((int) $revision->tenant_account_id !== (int) $revision->revisionQuote?->tenant_account_id) {
            throw new DomainException('Revizyon ve revizyon teklifi tenant bilgisi eşleşmiyor.');
        }

        if (! $revision->revisionQuote?->isRevisionDraft()) {
            throw new DomainException('Yalnız revizyon teklifleri uygulanabilir.');
        }

        if ((int) ($revision->revisionQuote?->source_order_id ?: 0) !== (int) $revision->order_id) {
            throw new DomainException('Revizyon teklifi yanlış kaynak siparişe bağlı.');
        }

        if (! $user->hasAnyPermissionInTenant(['create_quotes', 'edit_quotes', 'approve_quotes'], $revision->tenant_account_id)) {
            AuditLog::logPermissionViolation(
                $revision->tenant_account_id,
                $user->id,
                'apply_order_revision',
                OrderRevision::class,
                $revision->id
            );

            throw new DomainException('Revizyonu uygulama yetkiniz yok.');
        }

        if ($revision->applied_at || in_array($revision->status, [
            OrderRevision::STATUS_APPLIED,
            OrderRevision::STATUS_PARTIALLY_APPLIED,
        ], true)) {
            throw new DomainException(self::ALREADY_APPLIED_MESSAGE);
        }
    }

    private function applyItemLineChange(
        OrderRevisionChange $change,
        ?array $row,
        Collection $decisionMap,
        Order $order
    ): ?bool {
        if ($change->decision === OrderRevisionChange::DECISION_NO_CHANGE) {
            $this->markSkipped($change);
            return false;
        }

        if ($change->decision === OrderRevisionChange::DECISION_LOCKED) {
            $this->markBlocked($change);
            return false;
        }

        if ($change->decision === OrderRevisionChange::DECISION_MANUAL_REVIEW) {
            $this->markManual($change);
            return false;
        }

        if (! $row) {
            $this->markSkipped($change, 'Revizyon satırı eşleşmesi bulunamadı.');
            return false;
        }

        $sourceItem = $order->items->firstWhere('id', data_get($row, 'source_item_id'));
        $revisionItem = $this->findRevisionItem($order, data_get($row, 'revision_item_id'));

        if (! $sourceItem || ! $revisionItem) {
            $this->markSkipped($change, 'Kaynak veya revizyon kalemi bulunamadı.');
            return false;
        }

        $flags = (array) ($row['flags'] ?? []);
        if (($flags['is_new'] ?? false) || ($flags['is_removed'] ?? false) || ($flags['product_changed'] ?? false)) {
            $this->markManual($change);
            return false;
        }

        $wasApplied = false;
        $quantityDecision = data_get($decisionMap->get('adet_degisimi'), 'decision');
        $priceDecision = data_get($decisionMap->get('fiyat'), 'decision');

        if (($flags['quantity_changed'] ?? false) && $quantityDecision === 'Uygulanabilir') {
            $sourceItem->quantity = $revisionItem->quantity;
            $sourceItem->line_total = round((float) $sourceItem->unit_price * (float) $sourceItem->quantity, 4);
            $wasApplied = true;
        }

        if (($flags['price_changed'] ?? false) && in_array($priceDecision, ['Uygulanabilir', 'Kontrollü Uygulanabilir'], true)) {
            $sourceItem->unit_price = $revisionItem->unit_price;
            $sourceItem->line_total = $revisionItem->line_total;
            $wasApplied = true;
        }

        if (! $wasApplied) {
            $this->markSkipped($change);
            return false;
        }

        $sourceItem->print_total = round((float) $sourceItem->prints->sum('print_total'), 4);
        $sourceItem->price_snapshot = $this->refreshItemPriceSnapshot($sourceItem);
        $sourceItem->save();

        $this->markApplied($change);

        return true;
    }

    private function applyPrintLineChange(
        OrderRevisionChange $change,
        ?array $row,
        Collection $decisionMap,
        Order $order
    ): ?bool {
        if ($change->decision === OrderRevisionChange::DECISION_NO_CHANGE) {
            $this->markSkipped($change);
            return false;
        }

        if ($change->decision === OrderRevisionChange::DECISION_LOCKED) {
            $this->markBlocked($change);
            return false;
        }

        if ($change->decision === OrderRevisionChange::DECISION_MANUAL_REVIEW) {
            $this->markManual($change);
            return false;
        }

        if (! $row) {
            $this->markSkipped($change, 'Revizyon baskı eşleşmesi bulunamadı.');
            return false;
        }

        $sourcePrint = $order->items
            ->flatMap(fn (OrderItem $item) => $item->prints)
            ->firstWhere('id', data_get($row, 'source_print_id'));
        $revisionPrint = $this->findRevisionPrint($order, data_get($row, 'revision_print_id'));

        if (! $sourcePrint || ! $revisionPrint) {
            $this->markSkipped($change, 'Kaynak veya revizyon baskı kalemi bulunamadı.');
            return false;
        }

        $flags = (array) ($row['flags'] ?? []);
        if (($flags['is_new'] ?? false) || ($flags['is_removed'] ?? false) || ($flags['print_type_changed'] ?? false)) {
            $this->markManual($change);
            return false;
        }

        $wasApplied = false;
        $noteDecision = data_get($decisionMap->get('baski_notu'), 'decision');
        $priceDecision = data_get($decisionMap->get('fiyat'), 'decision');

        if (($flags['print_note_changed'] ?? false) && $noteDecision === 'Uygulanabilir') {
            $sourcePrint->note = $revisionPrint->note;
            $wasApplied = true;
        }

        if (($flags['price_changed'] ?? false) && in_array($priceDecision, ['Uygulanabilir', 'Kontrollü Uygulanabilir'], true)) {
            $sourcePrint->print_unit_price = $revisionPrint->print_unit_price;
            $sourcePrint->print_total = $revisionPrint->print_total;
            $wasApplied = true;
        }

        if (! $wasApplied) {
            $this->markSkipped($change);
            return false;
        }

        $sourcePrint->save();

        $parentItem = $sourcePrint->orderItem()->with('prints')->first();
        if ($parentItem) {
            $parentItem->print_total = round((float) $parentItem->prints->sum('print_total'), 4);
            $parentItem->price_snapshot = $this->refreshItemPriceSnapshot($parentItem);
            $parentItem->save();
        }

        $this->markApplied($change);

        return true;
    }

    private function applyOrderLevelChanges(OrderRevision $revision, Collection $decisionMap, Order $order): bool
    {
        $deliveryChange = $revision->changes()->firstWhere('field_key', 'teslim_bilgisi');
        $decision = data_get($decisionMap->get('teslim_bilgisi'), 'decision');

        if (! $deliveryChange) {
            return false;
        }

        if ($decision === 'Değişiklik Yok') {
            $this->markSkipped($deliveryChange);
            return false;
        }

        if ($decision === 'Kilitli') {
            $this->markBlocked($deliveryChange);
            return false;
        }

        if ($decision === 'Manuel Kontrol Gerekli') {
            $this->markManual($deliveryChange);
            return false;
        }

        $revisionQuote = $revision->revisionQuote;
        if (
            trim((string) $order->delivery_type) === trim((string) $revisionQuote->delivery_type)
            && (int) ($order->delivery_type_id ?: 0) === (int) ($revisionQuote->delivery_type_id ?: 0)
        ) {
            $this->markSkipped($deliveryChange);
            return false;
        }

        $order->delivery_type = $revisionQuote->delivery_type;
        $order->delivery_type_id = $revisionQuote->delivery_type_id;
        $order->save();

        $this->markApplied($deliveryChange);

        return true;
    }

    private function refreshItemPriceSnapshot(OrderItem $item): array
    {
        $snapshot = is_array($item->price_snapshot) ? $item->price_snapshot : [];
        $productTotal = round((float) $item->line_total, 4);
        $printTotal = round((float) $item->print_total, 4);
        $vatRate = (float) data_get($snapshot, 'vat_rate', 0);
        $printVatRate = (float) data_get($snapshot, 'print_vat_rate', $vatRate);
        $invoiceStatus = $item->relationLoaded('order')
            ? $item->order?->invoice_status
            : $item->order()->value('invoice_status');
        $invoiceTaxable = $invoiceStatus === 'fatura';

        $vatBreakdown = $invoiceTaxable ? array_values(array_filter([
            $productTotal > 0 ? [
                'rate' => $vatRate,
                'total' => round($productTotal * $vatRate / 100, 2),
                'scope' => 'product',
            ] : null,
            $printTotal > 0 ? [
                'rate' => $printVatRate,
                'total' => round($printTotal * $printVatRate / 100, 2),
                'scope' => 'print',
            ] : null,
        ])) : [];

        $snapshot['product_total'] = round($productTotal, 2);
        $snapshot['print_total'] = round($printTotal, 2);
        $snapshot['line_total'] = round($productTotal + $printTotal, 2);
        $snapshot['vat_rate'] = $invoiceTaxable ? $vatRate : 0.0;
        $snapshot['print_vat_rate'] = $invoiceTaxable ? $printVatRate : 0.0;
        $snapshot['line_vat_total'] = $invoiceTaxable
            ? round(($productTotal * $vatRate / 100) + ($printTotal * $printVatRate / 100), 2)
            : 0.0;
        $snapshot['vat_breakdown'] = $vatBreakdown;

        return $snapshot;
    }

    private function refreshOrderFinancialSnapshot(Order $order): void
    {
        $order->loadMissing('items.prints');

        $productTotal = 0.0;
        $printTotal = 0.0;
        $vatTotal = 0.0;
        $vatBreakdown = [];

        foreach ($order->items as $item) {
            $snapshot = is_array($item->price_snapshot) ? $item->price_snapshot : [];
            $productSlice = (float) data_get($snapshot, 'product_total', $item->line_total ?? 0);
            $printSlice = (float) data_get($snapshot, 'print_total', $item->print_total ?? 0);

            $productTotal += $productSlice;
            $printTotal += $printSlice;
            $vatTotal += (float) data_get($snapshot, 'line_vat_total', 0);

            foreach ((array) data_get($snapshot, 'vat_breakdown', []) as $row) {
                $rate = round((float) ($row['rate'] ?? 0), 4);
                $scope = (string) ($row['scope'] ?? 'general');
                $key = $rate . '|' . $scope;

                if (! isset($vatBreakdown[$key])) {
                    $vatBreakdown[$key] = [
                        'rate' => $rate,
                        'total' => 0.0,
                        'scope' => $scope,
                    ];
                }

                $vatBreakdown[$key]['total'] += (float) ($row['total'] ?? 0);
            }
        }

        $order->forceFill([
            'product_total' => round($productTotal, 2),
            'print_total' => round($printTotal, 2),
            'subtotal' => round($productTotal + $printTotal, 2),
            'vat_total' => round($vatTotal, 2),
            'grand_total' => round($productTotal + $printTotal + $vatTotal, 2),
            'vat_breakdown_json' => array_values(array_map(static function (array $row): array {
                $row['total'] = round((float) $row['total'], 2);
                return $row;
            }, $vatBreakdown)),
        ])->save();
    }

    private function resolveAppliedRevisionStatus(Collection $changes): string
    {
        $hasNonApplied = $changes->contains(fn (OrderRevisionChange $change) => in_array($change->apply_status, [
            OrderRevisionChange::APPLY_STATUS_BLOCKED,
            OrderRevisionChange::APPLY_STATUS_MANUAL_REQUIRED,
            OrderRevisionChange::APPLY_STATUS_SKIPPED,
            OrderRevisionChange::APPLY_STATUS_PENDING,
        ], true));

        return $hasNonApplied
            ? OrderRevision::STATUS_PARTIALLY_APPLIED
            : OrderRevision::STATUS_APPLIED;
    }

    private function resolveDecisionMatrixApplyStatus(OrderRevisionChange $change, ?array $outcome): void
    {
        if ($change->apply_status === OrderRevisionChange::APPLY_STATUS_APPLIED) {
            return;
        }

        if ($change->decision === OrderRevisionChange::DECISION_LOCKED) {
            $this->markBlocked($change);
            return;
        }

        if ($change->decision === OrderRevisionChange::DECISION_MANUAL_REVIEW) {
            $this->markManual($change);
            return;
        }

        if ($change->decision === OrderRevisionChange::DECISION_NO_CHANGE) {
            $this->markSkipped($change);
            return;
        }

        if (($outcome['applied'] ?? false) === true) {
            $this->markApplied($change);
            return;
        }

        $this->markSkipped($change);
    }

    private function trackDecisionOutcome(array &$decisionOutcomes, OrderRevisionChange $change, ?array $row): void
    {
        $flags = (array) ($row['flags'] ?? []);
        $status = $change->apply_status === OrderRevisionChange::APPLY_STATUS_APPLIED;

        if ($change->change_group === 'item_line') {
            if ($flags['quantity_changed'] ?? false) {
                $decisionOutcomes['adet_degisimi']['applied'] = ($decisionOutcomes['adet_degisimi']['applied'] ?? false) || $status;
            }

            if ($flags['price_changed'] ?? false) {
                $decisionOutcomes['fiyat']['applied'] = ($decisionOutcomes['fiyat']['applied'] ?? false) || $status;
            }
        }

        if ($change->change_group === 'print_line') {
            if ($flags['print_note_changed'] ?? false) {
                $decisionOutcomes['baski_notu']['applied'] = ($decisionOutcomes['baski_notu']['applied'] ?? false) || $status;
            }

            if ($flags['price_changed'] ?? false) {
                $decisionOutcomes['fiyat']['applied'] = ($decisionOutcomes['fiyat']['applied'] ?? false) || $status;
            }
        }
    }

    private function markApplied(OrderRevisionChange $change): void
    {
        $change->forceFill([
            'apply_status' => OrderRevisionChange::APPLY_STATUS_APPLIED,
            'applied_at' => now(),
        ])->save();
    }

    private function markSkipped(OrderRevisionChange $change, ?string $reason = null): void
    {
        $change->forceFill([
            'apply_status' => OrderRevisionChange::APPLY_STATUS_SKIPPED,
            'reason' => $reason ?: $change->reason,
            'applied_at' => null,
        ])->save();
    }

    private function markBlocked(OrderRevisionChange $change): void
    {
        $change->forceFill([
            'apply_status' => OrderRevisionChange::APPLY_STATUS_BLOCKED,
            'applied_at' => null,
        ])->save();
    }

    private function markManual(OrderRevisionChange $change): void
    {
        $change->forceFill([
            'apply_status' => OrderRevisionChange::APPLY_STATUS_MANUAL_REQUIRED,
            'applied_at' => null,
        ])->save();
    }

    private function normalizeFieldKey(string $label): string
    {
        $map = ['Ü' => 'u', 'ü' => 'u', 'İ' => 'i', 'ı' => 'i', 'Ö' => 'o', 'ö' => 'o', 'Ş' => 's', 'ş' => 's', 'Ç' => 'c', 'ç' => 'c', 'Ğ' => 'g', 'ğ' => 'g'];
        $label = strtr($label, $map);
        $label = strtolower($label);
        $label = preg_replace('/[^a-z0-9]+/', '_', $label) ?: 'field';

        return trim($label, '_');
    }

    private function findRevisionItem(Order $order, ?int $revisionItemId): ?OrderItem
    {
        if (! $revisionItemId) {
            return null;
        }

        return $order->revisionRecord?->revisionQuote?->items?->firstWhere('id', $revisionItemId)
            ?: OrderItem::query()->whereKey($revisionItemId)->first();
    }

    private function findRevisionPrint(Order $order, ?int $revisionPrintId): ?OrderItemPrint
    {
        if (! $revisionPrintId) {
            return null;
        }

        return $order->revisionRecord?->revisionQuote?->items
            ?->flatMap(fn (OrderItem $item) => $item->prints)
            ->firstWhere('id', $revisionPrintId)
            ?: OrderItemPrint::query()->whereKey($revisionPrintId)->first();
    }
}
