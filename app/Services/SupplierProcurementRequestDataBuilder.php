<?php

namespace App\Services;

use App\Models\OrderItemProcurement;
use App\Models\SupplierProcurementRequest;
use App\Models\SupplierProcurementRequestItem;
use App\Models\TenantAccount;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class SupplierProcurementRequestDataBuilder
{
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
            $suggestedPrice = $item->purchase_list_price;
            $missingPrice = false;

            if ($suggestedPrice === null && $item->procurement) {
                $suggestedPrice = $this->suggestPurchaseListPrice($item->procurement);
                $missingPrice = $suggestedPrice === null;
            }

            $item->setAttribute('suggested_purchase_list_price', $suggestedPrice ?? 0.0);
            $item->setAttribute('purchase_list_price_missing', $missingPrice);

            if ($item->purchase_list_price === null && $suggestedPrice !== null) {
                $item->purchase_list_price = round((float) $suggestedPrice, 2);
                $item->recalculatePurchaseTotals();
            }

            if ($item->discount_rate === null) {
                $item->discount_rate = 0.0;
            }

            $salesReference = $this->buildSalesReference($item);
            $item->setAttribute('sales_unit_price', $salesReference['sales_unit_price']);
            $item->setAttribute('sales_total', $salesReference['sales_total']);
            $item->setAttribute('sales_reference_missing', $salesReference['sales_reference_missing']);
            $item->setAttribute('purchase_sales_warnings', $salesReference['warnings']);

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

        $procurement->loadMissing('orderItem');

        $snapshot = is_array($procurement->snapshot) ? $procurement->snapshot : [];
        $productSnapshot = is_array($procurement->orderItem?->product_snapshot) ? $procurement->orderItem->product_snapshot : [];
        $itemPriceSnapshot = is_array($procurement->orderItem?->price_snapshot) ? $procurement->orderItem->price_snapshot : [];

        $candidates = [
            data_get($snapshot, 'purchase_list_price'),
            data_get($snapshot, 'supplier_list_price'),
            data_get($snapshot, 'source_list_price'),
            data_get($snapshot, 'price_snapshot.list_price'),
            data_get($snapshot, 'supplier_price_snapshot.list_price'),
            data_get($snapshot, 'catalog_price_snapshot.list_price'),
            data_get($productSnapshot, 'purchase_list_price'),
            data_get($productSnapshot, 'supplier_list_price'),
            data_get($productSnapshot, 'source_list_price'),
            data_get($productSnapshot, 'list_price'),
            data_get($productSnapshot, 'list_price_snapshot'),
            data_get($productSnapshot, 'supply_price_snapshot.list_price'),
            data_get($productSnapshot, 'price_snapshot.list_price'),
            data_get($productSnapshot, 'meta.price_snapshot.list_price'),
            data_get($itemPriceSnapshot, 'purchase_list_price'),
            data_get($itemPriceSnapshot, 'supplier_list_price'),
            data_get($itemPriceSnapshot, 'list_price'),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            return round((float) $candidate, 2);
        }

        return null;
    }

    protected function buildSalesReference(SupplierProcurementRequestItem $item): array
    {
        $orderItem = $item->orderItem ?: $item->procurement?->orderItem;
        $priceSnapshot = is_array($orderItem?->price_snapshot) ? $orderItem->price_snapshot : [];

        $salesUnitPrice = $this->firstNumericValue([
            $orderItem?->unit_price,
            data_get($priceSnapshot, 'unit_price'),
            data_get($priceSnapshot, 'sales_unit_price'),
        ]);

        $salesTotal = $this->firstNumericValue([
            $orderItem?->line_total,
            data_get($priceSnapshot, 'line_total'),
            data_get($priceSnapshot, 'sales_total'),
        ]);

        return [
            'sales_unit_price' => $salesUnitPrice,
            'sales_total' => $salesTotal,
            'sales_reference_missing' => $salesUnitPrice === null && $salesTotal === null,
            'warnings' => $this->buildPurchaseSalesWarnings(
                $item->purchase_list_price !== null ? round((float) $item->purchase_list_price, 2) : null,
                $item->purchase_unit_price !== null ? round((float) $item->purchase_unit_price, 2) : null,
                $item->purchase_total !== null ? round((float) $item->purchase_total, 2) : null,
                $salesUnitPrice,
                $salesTotal
            ),
        ];
    }

    protected function buildPurchaseSalesWarnings(
        ?float $purchaseListPrice,
        ?float $purchaseUnitPrice,
        ?float $purchaseTotal,
        ?float $salesUnitPrice,
        ?float $salesTotal
    ): array {
        $warnings = [];

        if (($purchaseListPrice === null || abs($purchaseListPrice) < 0.0001)
            && ($purchaseUnitPrice === null || abs($purchaseUnitPrice) < 0.0001)) {
            $warnings[] = 'Liste fiyatı bulunamadı; özel alış fiyatı girin';
        }

        if ($salesUnitPrice === null && $salesTotal === null) {
            $warnings[] = 'Satış fiyatı referansı bulunamadı';
        }

        if ($purchaseUnitPrice !== null && $salesUnitPrice !== null) {
            if ($purchaseUnitPrice > $salesUnitPrice) {
                $warnings[] = 'Alış fiyatı satış fiyatını aşıyor';
            } elseif ($salesUnitPrice > 0
                && $purchaseUnitPrice >= ($salesUnitPrice * 0.90)
                && $purchaseUnitPrice <= $salesUnitPrice) {
                $warnings[] = 'Alış fiyatı satış fiyatına çok yakın';
            }
        }

        if ($purchaseTotal !== null && $salesTotal !== null && $purchaseTotal > $salesTotal) {
            $warnings[] = 'Alış toplamı satış toplamını aşıyor';
        }

        return array_values(array_unique($warnings));
    }

    protected function firstNumericValue(array $candidates): ?float
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            return round((float) $candidate, 2);
        }

        return null;
    }
}
