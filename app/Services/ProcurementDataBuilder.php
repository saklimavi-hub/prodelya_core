<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;

class ProcurementDataBuilder
{
    public function build(OrderItem $item, ?OrderItemWorkForm $workForm = null): array
    {
        $item->loadMissing([
            'order',
            'supplierSource.supplier',
            'legacySupplierCompany',
            'tenantCatalogProduct',
            'tenantCatalogProductVariant.catalogProduct',
        ]);

        $workForm ??= $item->workForm;

        $productSnapshot = is_array($item->product_snapshot) ? $item->product_snapshot : [];
        $stockSnapshot = is_array($item->stock_snapshot) ? $item->stock_snapshot : [];
        $supplierSource = $item->supplierSource;
        $supplier = $supplierSource?->supplier;
        $supplierName = $supplier?->name
            ?: data_get($productSnapshot, 'supplier_name')
            ?: $item->legacySupplierCompany?->legal_name;

        $fulfillmentSource = $this->suggestFulfillmentSource($item);
        $stockHandlingMode = $this->resolveStockHandlingMode($fulfillmentSource);

        return [
            'order_id' => $item->order_id,
            'order_number' => $item->order?->document_number,
            'work_form_id' => $workForm?->id,
            'work_form_number' => $workForm?->work_form_number,
            'order_item_id' => $item->id,
            'product_name' => $item->product_name ?: data_get($productSnapshot, 'product_name'),
            'product_code' => $item->product_code ?: data_get($productSnapshot, 'product_code'),
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'product_image_url' => data_get($productSnapshot, 'image_url'),
            'supplier_id' => $supplier?->id ?: $item->supplier_id,
            'supplier_name' => $supplierName,
            'supplier_source_id' => $supplierSource?->id ?: $item->supplier_source_id,
            'supplier_source_name' => $supplierSource?->source_name,
            'catalog_source' => data_get($productSnapshot, 'catalog_source_label')
                ?: data_get($productSnapshot, 'catalog_source')
                ?: $item->catalog_source,
            'stock_snapshot_summary' => $this->buildStockSnapshotSummary($stockSnapshot, $stockHandlingMode),
            'stock_snapshot_reference' => $this->buildStockSnapshotReference($stockSnapshot),
            'warning_labels' => $this->sanitizeWarningLabels($productSnapshot),
            'item_type' => $item->item_type,
            'product_source' => $item->product_source,
            'stock_handling_mode' => $stockHandlingMode,
        ];
    }

    public function buildWorkFormSnapshot(OrderItemProcurement $procurement): array
    {
        $snapshot = is_array($procurement->snapshot) ? $procurement->snapshot : [];
        $note = $this->sanitizeNote($procurement->notes);

        return [
            'procurement_id' => $procurement->id,
            'requires_procurement' => (bool) $procurement->requires_procurement,
            'procurement_status' => $procurement->procurement_status,
            'procurement_status_label' => $procurement->safeStatusLabel(),
            'fulfillment_source' => $procurement->fulfillment_source,
            'fulfillment_source_label' => $procurement->safeFulfillmentSourceLabel(),
            'stock_handling_mode' => $snapshot['stock_handling_mode'] ?? $this->resolveStockHandlingMode($procurement->fulfillment_source),
            'stock_handling_mode_label' => $this->stockHandlingModeLabel(
                $snapshot['stock_handling_mode'] ?? $this->resolveStockHandlingMode($procurement->fulfillment_source)
            ),
            'requested_quantity' => (float) $procurement->requested_quantity,
            'local_allocated_quantity' => (float) $procurement->local_allocated_quantity,
            'supplier_requested_quantity' => (float) $procurement->supplier_requested_quantity,
            'received_quantity' => (float) $procurement->received_quantity,
            'remaining_quantity' => (float) $procurement->remaining_quantity,
            'public_status_label' => $this->publicStatusLabel($procurement),
            'note' => $note,
            'updated_at' => optional($procurement->updated_at)->toAtomString(),
        ];
    }

    public function suggestFulfillmentSource(OrderItem $item): string
    {
        if ($item->isCustomerSupplied() || $item->product_source === 'customer_supplied') {
            return OrderItemProcurement::FULFILLMENT_CUSTOMER_SUPPLIED;
        }

        if (in_array($item->item_type, ['service', 'print_service'], true)) {
            return OrderItemProcurement::FULFILLMENT_NOT_REQUIRED;
        }

        if ($item->isFromLocalStock()) {
            return OrderItemProcurement::FULFILLMENT_LOCAL_STOCK;
        }

        if ($item->isFromSupplierFeed() || filled($item->supplier_source_id) || filled($item->supplier_id)) {
            return OrderItemProcurement::FULFILLMENT_SUPPLIER;
        }

        return OrderItemProcurement::FULFILLMENT_SUPPLIER;
    }

    public function resolveStockHandlingMode(string $fulfillmentSource): string
    {
        return match ($fulfillmentSource) {
            OrderItemProcurement::FULFILLMENT_LOCAL_STOCK,
            OrderItemProcurement::FULFILLMENT_MIXED => 'local_operational_stock',
            OrderItemProcurement::FULFILLMENT_CUSTOMER_SUPPLIED => 'customer_supplied',
            OrderItemProcurement::FULFILLMENT_NOT_REQUIRED => 'not_required',
            default => 'supplier_reference_stock',
        };
    }

    public function stockHandlingModeLabel(string $mode): string
    {
        return match ($mode) {
            'local_operational_stock' => 'Local Operasyonel Stok',
            'supplier_reference_stock' => 'Tedarikçi Referans Stoğu',
            'customer_supplied' => 'Müşteri Ürünü',
            'not_required' => 'Tedarik Gerekmiyor',
            default => ucfirst(str_replace('_', ' ', $mode)),
        };
    }

    public function publicStatusLabel(OrderItemProcurement $procurement): string
    {
        return match ($procurement->procurement_status) {
            OrderItemProcurement::STATUS_PENDING => 'Ürününüz hazırlanıyor',
            OrderItemProcurement::STATUS_REQUEST_CREATED,
            OrderItemProcurement::STATUS_SUPPLIER_ORDERED => 'Ürün tedarik sürecinde',
            OrderItemProcurement::STATUS_PARTIALLY_RECEIVED => 'Ürünün bir kısmı hazırlandı',
            OrderItemProcurement::STATUS_FULLY_RECEIVED => 'Ürün üretime hazır',
            OrderItemProcurement::STATUS_CUSTOMER_WAITING => 'Müşteri ürünü bekleniyor',
            OrderItemProcurement::STATUS_CUSTOMER_RECEIVED => 'Ürün üretime hazır',
            OrderItemProcurement::STATUS_NOT_REQUIRED => 'Tedarik gerekmiyor',
            OrderItemProcurement::STATUS_CANCELLED => 'Tedarik süreci durduruldu',
            default => $procurement->safeStatusLabel(),
        };
    }

    private function buildStockSnapshotSummary(array $stockSnapshot, string $stockHandlingMode): array
    {
        return [
            'stock_handling_mode' => $stockHandlingMode,
            'local_stock_quantity' => (float) ($stockSnapshot['local_stock_quantity'] ?? 0),
            'supplier_stock_quantity' => (float) ($stockSnapshot['supplier_stock_quantity'] ?? 0),
            'safe_stock_quantity' => (float) ($stockSnapshot['safe_stock_quantity'] ?? 0),
            'local_stock_priority' => (bool) ($stockSnapshot['local_stock_priority'] ?? false),
        ];
    }

    private function buildStockSnapshotReference(array $stockSnapshot): array
    {
        return array_filter([
            'local_stock_quantity' => array_key_exists('local_stock_quantity', $stockSnapshot)
                ? (float) $stockSnapshot['local_stock_quantity']
                : null,
            'supplier_stock_quantity' => array_key_exists('supplier_stock_quantity', $stockSnapshot)
                ? (float) $stockSnapshot['supplier_stock_quantity']
                : null,
            'safe_stock_quantity' => array_key_exists('safe_stock_quantity', $stockSnapshot)
                ? (float) $stockSnapshot['safe_stock_quantity']
                : null,
            'effective_stock_quantity' => array_key_exists('effective_stock_quantity', $stockSnapshot)
                ? (float) $stockSnapshot['effective_stock_quantity']
                : null,
            'local_stock_priority' => array_key_exists('local_stock_priority', $stockSnapshot)
                ? (bool) $stockSnapshot['local_stock_priority']
                : null,
            'snapshot_taken_at' => $stockSnapshot['snapshot_taken_at'] ?? null,
        ], static fn ($value) => $value !== null);
    }

    private function sanitizeWarningLabels(array $productSnapshot): array
    {
        $labels = (array) data_get($productSnapshot, 'warning_labels', data_get($productSnapshot, 'warning_badges', []));
        $forbiddenFragments = $this->forbiddenTextFragments();

        return collect($labels)
            ->filter(static fn ($label) => is_scalar($label) && trim((string) $label) !== '')
            ->map(static fn ($label) => trim((string) $label))
            ->reject(function (string $label) use ($forbiddenFragments): bool {
                $lower = mb_strtolower($label);

                foreach ($forbiddenFragments as $fragment) {
                    if (str_contains($lower, $fragment)) {
                        return true;
                    }
                }

                return false;
            })
            ->unique()
            ->values()
            ->all();
    }

    private function sanitizeNote(mixed $note): ?string
    {
        if (!is_scalar($note)) {
            return null;
        }

        $value = trim((string) $note);

        if ($value === '') {
            return null;
        }

        $lower = mb_strtolower($value);
        foreach ($this->forbiddenTextFragments() as $fragment) {
            if (str_contains($lower, $fragment)) {
                return null;
            }
        }

        return $value;
    }

    private function forbiddenTextFragments(): array
    {
        return ['fiyat', 'price', 'cost', 'maliyet', 'kdv', 'kar', 'kâr', 'margin', 'toplam'];
    }
}
