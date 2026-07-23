<?php

namespace App\Services;

use App\Models\OrderItemProcurement;
use App\Models\SupplierProcurementRequest;
use App\Models\SupplierProcurementRequestItem;
use App\Models\TenantAccount;
use App\Services\Procurement\ProcurementPurchasePricingService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SupplierProcurementRequestDataBuilder
{
    public function __construct(
        private readonly ProcurementPurchasePricingService $purchasePricingService,
    ) {
    }

    public function buildSupplierGroups(TenantAccount $tenant, array $filters = []): array
    {
        $procurements = $this->baseOpenProcurementQuery($tenant, $filters)
            ->with(['supplier', 'order', 'workForm'])
            ->get();

        $openRequestCounts = SupplierProcurementRequest::query()
            ->selectRaw('supplier_id, COUNT(DISTINCT id) as aggregate_count')
            ->where('tenant_account_id', $tenant->id)
            ->whereNotNull('supplier_id')
            ->whereNotIn('status', [
                SupplierProcurementRequest::STATUS_COMPLETED,
                SupplierProcurementRequest::STATUS_CANCELLED,
            ])
            ->groupBy('supplier_id')
            ->pluck('aggregate_count', 'supplier_id');

        $linkedProcurementIds = $this->openRequestProcurementIds($tenant)->all();

        return $procurements
            ->groupBy('supplier_id')
            ->map(function (Collection $group, int|string $supplierId) use ($openRequestCounts, $linkedProcurementIds): array {
                $supplier = $group->first()?->supplier;
                $statusSummary = $group
                    ->groupBy('procurement_status')
                    ->map(fn (Collection $rows) => $rows->count())
                    ->sortKeys()
                    ->all();

                $candidateCount = $group
                    ->reject(fn (OrderItemProcurement $procurement) => in_array($procurement->id, $linkedProcurementIds, true))
                    ->count();

                return [
                    'supplier_id' => (int) $supplierId,
                    'supplier_name' => $supplier?->name ?: ('Tedarikçi #' . $supplierId),
                    'open_item_count' => $group->count(),
                    'total_missing_quantity' => round(
                        $group->sum(fn (OrderItemProcurement $procurement) => (float) $procurement->remaining_quantity),
                        2
                    ),
                    'open_request_count' => (int) ($openRequestCounts[(int) $supplierId] ?? 0),
                    'statuses_summary' => $statusSummary,
                    'can_create_request' => $candidateCount > 0,
                    'candidate_item_count' => $candidateCount,
                ];
            })
            ->sortBy('supplier_name')
            ->values()
            ->all();
    }

    public function getCandidateProcurementsForSupplier(TenantAccount $tenant, int $supplierId, array $filters = []): EloquentCollection
    {
        return $this->baseOpenProcurementQuery($tenant, $filters)
            ->where('supplier_id', $supplierId)
            ->whereNotIn('id', $this->openRequestProcurementIdsSubquery($tenant))
            ->with(['supplier', 'order', 'orderItem', 'workForm'])
            ->orderBy('id')
            ->get();
    }

    public function buildRequestEditData(\App\Models\SupplierProcurementRequest $request): array
    {
        $request->loadMissing(['supplier', 'items.order', 'items.orderItem', 'items.workForm', 'items.procurement.orderItem']);

        $items = $request->items->map(function (SupplierProcurementRequestItem $item) {
            $suggestedPrice = $item->purchase_list_price_try ?? $item->purchase_list_price;
            $missingPrice = false;

            if ($suggestedPrice === null && $item->procurement) {
                $suggestedPrice = $this->suggestPurchaseListPrice($item->procurement);
                $missingPrice = $suggestedPrice === null;
            }

            $item->setAttribute('suggested_purchase_list_price', $suggestedPrice ?? 0.0);
            $item->setAttribute('purchase_list_price_missing', $missingPrice);

            if ($item->purchase_list_price === null && $suggestedPrice !== null) {
                $item->purchase_list_price = round((float) $suggestedPrice, 2);
            }

            if ($item->purchase_list_price_try === null && $suggestedPrice !== null) {
                $item->purchase_list_price_try = round((float) $suggestedPrice, 6);
            }

            if ($item->discount_rate === null) {
                $item->discount_rate = 0.0;
            }

            $item->setAttribute('purchase_ui', $this->buildPurchasePresentation($item));

            return $item;
        });

        return [
            'request' => $request,
            'supplier' => $request->supplier,
            'items' => $items,
            'included_item_count' => $items->count(),
            'total_quantity' => round($items->sum(fn ($item) => (float) $item->requested_quantity), 2),
            'purchase_total' => round($items->sum(fn ($item) => (float) ($item->purchase_total ?? 0)), 2),
            'has_missing_purchase_prices' => $items->contains(fn ($item) => (bool) ($item->purchase_list_price_missing ?? false)),
            'legacy_purchase_truth_item_count' => $items->filter(fn (SupplierProcurementRequestItem $item) => !$item->hasCanonicalPurchaseSnapshot())->count(),
        ];
    }

    public function buildPrintData(\App\Models\SupplierProcurementRequest $request): array
    {
        $request->loadMissing(['supplier', 'creator', 'items.order']);

        $items = $request->items->map(function (SupplierProcurementRequestItem $item): array {
            return [
                'order_number' => $item->order?->document_number,
                'product_code' => $item->product_code,
                'product_name' => $item->product_name,
                'note' => $item->note,
                'requested_quantity' => round((float) $item->requested_quantity, 2),
                'unit' => $item->safeUnitLabel(),
            ];
        })->values()->all();

        $unitTotals = $request->items
            ->groupBy(fn (SupplierProcurementRequestItem $item) => $item->safeUnitLabel())
            ->map(fn ($rows, $unit) => [
                'unit' => $unit,
                'quantity' => round(collect($rows)->sum(fn ($row) => (float) $row->requested_quantity), 2),
            ])
            ->values()
            ->all();

        $supplierConfig = is_array($request->supplier?->config) ? $request->supplier->config : [];

        return [
            'supplier_name' => $request->supplier?->name,
            'request_number' => $request->request_number,
            'request_date' => optional($request->request_date)->format('Y-m-d'),
            'status_label' => $request->safeStatusLabel(),
            'prepared_by' => $request->creator?->name,
            'supplier_phone' => $request->supplier?->contact_phone,
            'supplier_email' => $request->supplier?->contact_email,
            'supplier_contact_name' => data_get($supplierConfig, 'contact_name')
                ?: data_get($supplierConfig, 'contact_person')
                ?: data_get($supplierConfig, 'authorized_person'),
            'items' => $items,
            'total_quantity' => round($request->items->sum(fn ($item) => (float) $item->requested_quantity), 2),
            'item_count' => count($items),
            'unit_totals' => $unitTotals,
            'has_multiple_units' => count($unitTotals) > 1,
        ];
    }

    protected function baseOpenProcurementQuery(TenantAccount $tenant, array $filters = [])
    {
        $blockedStatuses = [
            OrderItemProcurement::STATUS_FULLY_RECEIVED,
            OrderItemProcurement::STATUS_CANCELLED,
            OrderItemProcurement::STATUS_NOT_REQUIRED,
            OrderItemProcurement::STATUS_CUSTOMER_WAITING,
            OrderItemProcurement::STATUS_CUSTOMER_RECEIVED,
        ];

        $query = OrderItemProcurement::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('requires_procurement', true)
            ->whereNotNull('supplier_id')
            ->where('remaining_quantity', '>', 0)
            ->whereIn('fulfillment_source', [
                OrderItemProcurement::FULFILLMENT_SUPPLIER,
                OrderItemProcurement::FULFILLMENT_MIXED,
            ])
            ->whereNotIn('procurement_status', $blockedStatuses)
            ->whereHas('supplier', function ($supplierQuery) use ($tenant) {
                $supplierQuery
                    ->where('status', 'active')
                    ->whereHas('tenants', function ($tenantQuery) use ($tenant) {
                        $tenantQuery
                            ->where('tenant_accounts.id', $tenant->id)
                            ->where('tenant_supplier_access.is_active', true)
                            ->where('tenant_supplier_access.can_request_purchase', true);
                    });
            });

        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }

        if (!empty($filters['procurement_status'])) {
            $query->where('procurement_status', (string) $filters['procurement_status']);
        }

        return $query;
    }

    protected function openRequestProcurementIds(TenantAccount $tenant): Collection
    {
        return SupplierProcurementRequestItem::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereHas('request', function ($query) use ($tenant) {
                $query->where('tenant_account_id', $tenant->id)
                    ->whereNotIn('status', [
                        SupplierProcurementRequest::STATUS_COMPLETED,
                        SupplierProcurementRequest::STATUS_CANCELLED,
                    ]);
            })
            ->pluck('order_item_procurement_id')
            ->filter()
            ->map(static fn ($id) => (int) $id)
            ->values();
    }

    protected function openRequestProcurementIdsSubquery(TenantAccount $tenant)
    {
        return SupplierProcurementRequestItem::query()
            ->select('order_item_procurement_id')
            ->where('tenant_account_id', $tenant->id)
            ->whereHas('request', function ($query) use ($tenant) {
                $query->where('tenant_account_id', $tenant->id)
                    ->whereNotIn('status', [
                        SupplierProcurementRequest::STATUS_COMPLETED,
                        SupplierProcurementRequest::STATUS_CANCELLED,
                    ]);
            });
    }

    public function suggestPurchaseListPrice(?OrderItemProcurement $procurement): ?float
    {
        if (!$procurement) {
            return null;
        }

        return $this->purchasePricingService->suggestLegacyListPriceTry($procurement);
    }

    public function buildPurchasePresentation(SupplierProcurementRequestItem $item): array
    {
        $snapshot = is_array($item->purchase_price_snapshot) ? $item->purchase_price_snapshot : [];
        $sourceAmount = $item->purchase_source_amount ?? data_get($snapshot, 'purchase_source_amount');
        $sourceCurrency = (string) ($item->purchase_source_currency ?? data_get($snapshot, 'purchase_source_currency') ?? '');
        $tryEquivalent = $item->purchase_list_price_try ?? data_get($snapshot, 'purchase_list_price_try') ?? $item->purchase_list_price;
        $fxRate = $item->purchase_fx_rate ?? data_get($snapshot, 'purchase_fx_rate');
        $fxRateDate = $item->purchase_fx_rate_date ?? data_get($snapshot, 'purchase_fx_rate_date');
        $calculatedUnit = $item->purchase_calculated_unit_price ?? data_get($snapshot, 'purchase_calculated_unit_price');
        $finalUnit = $item->purchase_unit_price ?? data_get($snapshot, 'purchase_final_unit_price');
        $warningCode = (string) (data_get($snapshot, 'warning_code') ?? '');
        $resolutionStatus = (string) (data_get($snapshot, 'resolution_status') ?? '');
        $manualOverride = (bool) ($item->purchase_manual_override ?? data_get($snapshot, 'purchase_manual_override', false));
        $sourceDisplayCurrency = $sourceCurrency === 'TRY' ? 'TL' : $sourceCurrency;
        $sourceDisplay = $sourceAmount !== null && $sourceCurrency !== ''
            ? $this->formatMoney($sourceAmount, 2, $sourceDisplayCurrency)
            : null;
        $tryEquivalentDisplay = $tryEquivalent !== null
            ? $this->formatMoney($tryEquivalent, 2, 'TL')
            : null;
        $rateDisplay = null;

        if ($sourceCurrency !== '' && $sourceCurrency !== 'TRY' && $fxRate !== null) {
            $rateDisplay = '1 ' . $sourceCurrency . ' = ' . $this->formatNumber($fxRate, 4) . ' TL';
        }

        return [
            'resolution_status' => $resolutionStatus,
            'warning_code' => $warningCode,
            'warning_text' => $this->resolvePurchaseWarningText($warningCode, $resolutionStatus),
            'source_currency' => $sourceCurrency,
            'source_display' => $sourceDisplay,
            'try_equivalent_display' => $tryEquivalentDisplay,
            'rate_display' => $rateDisplay,
            'rate_date_display' => $this->formatDate($fxRateDate),
            'discount_display' => $this->formatNumber($item->discount_rate ?? 0, 2),
            'calculated_unit_value' => $calculatedUnit !== null ? round((float) $calculatedUnit, 6) : null,
            'calculated_unit_display' => $calculatedUnit !== null ? $this->formatMoney($calculatedUnit, 2, 'TL') : null,
            'effective_unit_value' => $finalUnit !== null ? round((float) $finalUnit, 6) : null,
            'final_unit_display' => $finalUnit !== null ? $this->formatMoney($finalUnit, 2, 'TL') : null,
            'manual_override' => $manualOverride,
            'purchase_total_display' => $item->purchase_total !== null ? $this->formatMoney($item->purchase_total, 2, 'TL') : null,
        ];
    }

    protected function resolvePurchaseWarningText(string $warningCode, string $resolutionStatus): ?string
    {
        return match (true) {
            $warningCode === 'missing_supplier_purchase_source' => 'Tedarikçi liste fiyatı bulunamadı',
            $warningCode === 'missing_fx_rate' => 'Kur bulunamadı; manuel alış birim fiyatı girin',
            $warningCode === 'unsupported_source_currency' => 'Desteklenmeyen para birimi; manuel alış birim fiyatı girin',
            $resolutionStatus === 'legacy_snapshot' => 'Eski procurement satırı; supplier kaynak para birimi geçmiş snapshotta yok',
            default => null,
        };
    }

    protected function formatMoney(mixed $value, int $precision, string $currency): string
    {
        return $this->formatNumber($value, $precision) . ' ' . $currency;
    }

    protected function formatNumber(mixed $value, int $precision): string
    {
        return number_format((float) $value, $precision, ',', '.');
    }

    protected function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->format('d.m.Y');
    }
}
