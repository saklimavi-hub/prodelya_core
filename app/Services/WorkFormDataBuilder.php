<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrintSetupRequirement;
use App\Services\PromotionIntermediateElementPolicy;
class WorkFormDataBuilder
{
    public function __construct(
        protected PromotionIntermediateElementPolicy $promotionIntermediateElementPolicy
    ) {}

    public function build(Order $order, OrderItem $item, int $itemSequence): array
    {
        $order->loadMissing(['customer.contacts', 'customer.addresses']);
        $item->loadMissing([
            'prints.subcontractorCompany',
            'prints.tenantPrintSetting.standardPrintType',
            'prints.setupRequirements.assignedCompany',
            'tenantCatalogProductVariant.catalogProduct',
            'tenantCatalogProductVariant',
            'tenantCatalogProduct',
            'legacySupplierCompany',
        ]);

        return [
            'order_snapshot' => $this->buildOrderSnapshot($order),
            'customer_snapshot' => $this->buildCustomerSnapshot($order->customer),
            'product_snapshot' => $this->buildProductSnapshot($item, $itemSequence),
            'print_snapshot' => $this->buildPrintSnapshot($item, $itemSequence),
            'graphic_snapshot' => $this->buildGraphicSnapshot($item),
            'production_snapshot' => $this->buildProductionSnapshot($item),
            'delivery_snapshot' => $this->buildDeliverySnapshot($order),
        ];
    }

    private function buildOrderSnapshot(Order $order): array
    {
        return [
            'order_id' => $order->id,
            'document_number' => $order->document_number,
            'source_quote_number' => $order->source_quote_number,
            'order_date' => optional($order->quote_date ?? $order->created_at)?->format('Y-m-d'),
            'created_at' => optional($order->created_at)?->toAtomString(),
            'delivery_type' => $order->delivery_type,
            'invoice_status' => $order->invoice_status,
            'notes' => $order->notes,
        ];
    }

    private function buildCustomerSnapshot(?Company $customer): array
    {
        if (!$customer) {
            return [
                'company_name' => '-',
                'contact_name' => null,
                'phone' => null,
                'email' => null,
                'address' => null,
            ];
        }

        $primaryContact = $customer->getPrimaryContact();
        $defaultAddress = $customer->getDefaultDeliveryAddress()
            ?? $customer->getDefaultAddress()
            ?? $customer->addresses()->first();

        return [
            'company_name' => $customer->legal_name,
            'contact_name' => $primaryContact?->fullname_with_title ?? $primaryContact?->name,
            'phone' => $primaryContact?->phone ?: $primaryContact?->mobile ?: $customer->phone ?: $customer->mobile,
            'email' => $primaryContact?->email ?: $customer->email,
            'address' => $defaultAddress?->address_with_title ?? $defaultAddress?->full_address,
        ];
    }

    private function buildProductSnapshot(OrderItem $item, int $itemSequence): array
    {
        $productSnapshot = is_array($item->product_snapshot) ? $item->product_snapshot : [];
        $warningLabels = array_values(array_unique(array_filter(array_map(
            static fn ($label) => is_scalar($label) ? trim((string) $label) : null,
            (array) data_get($productSnapshot, 'warning_badges', [])
        ))));
        $catalogSource = data_get($productSnapshot, 'catalog_source_label')
            ?: data_get($productSnapshot, 'catalog_source')
            ?: $item->catalog_source;

        return [
            'item_sequence' => $itemSequence,
            'product_name' => $item->product_name,
            'product_code' => $item->product_code ?: data_get($productSnapshot, 'product_code'),
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'image_url' => data_get($productSnapshot, 'image_url'),
            'variant_name' => $this->resolveVariantName($item, $productSnapshot),
            'supplier_name' => data_get($productSnapshot, 'supplier_name') ?: $item->legacySupplierCompany?->legal_name,
            'catalog_source' => $catalogSource,
            'warning_labels' => $warningLabels,
        ];
    }

    private function buildPrintSnapshot(OrderItem $item, int $itemSequence): array
    {
        $alpha = 'abcdefghijklmnopqrstuvwxyz';

        return $item->prints
            ->values()
            ->map(function ($print, int $index) use ($itemSequence, $alpha) {
                $setupSummary = $this->promotionIntermediateElementPolicy->shouldRender()
                    ? $print->setupStatusSummary()
                    : $this->emptySetupSummary();

                return [
                    'sequence' => $itemSequence . ($alpha[$index] ?? (string) ($index + 1)),
                    'print_type' => $print->displayPrintType(),
                    'print_option' => $print->print_option,
                    'production_type' => $print->production_type,
                    'subcontractor_company_name' => $print->subcontractorCompany?->legal_name,
                    'print_quantity' => (float) ($print->print_quantity ?? 0),
                    'note' => $print->note,
                    'production_note' => $print->production_note,
                    'cliche_status' => $this->promotionIntermediateElementPolicy->shouldRender() ? $print->cliche_status : null,
                    'status' => $print->status,
                    'graphic_required' => $print->effectiveRequiresGraphic(),
                    'production_required' => $print->effectiveRequiresProduction(),
                    'graphic_required_label' => $print->effectiveRequiresGraphic() ? 'Grafik gerekli' : 'Grafik gerekli değil',
                    'production_required_label' => $print->effectiveRequiresProduction() ? 'Üretim gerekli' : 'Üretim gerekli değil',
                    'setup_required' => $this->promotionIntermediateElementPolicy->shouldRender() && (bool) data_get($setupSummary, 'required', false),
                    'setup_summary' => $setupSummary,
                ];
            })
            ->all();
    }

    private function buildGraphicSnapshot(OrderItem $item): array
    {
        if (!$item->has_print) {
            return [
                'status' => 'gerekli_degil',
                'status_label' => 'Grafik Gerekli Değil',
                'public_status_label' => 'Grafik gerekli değil',
                'approval_type' => null,
                'approval_status' => 'gerekli_degil',
                'approval_status_label' => 'Onay Gerekmiyor',
                'approved_by_name' => null,
                'designer_name' => null,
                'short_note' => 'Bu kalemde baskı olmadığı için grafik süreci başlatılmaz.',
                'revision_note' => null,
                'primary_visual_attachment_id' => null,
                'updated_at' => null,
            ];
        }

        $requiresGraphic = $item->prints->isEmpty()
            ? true
            : $item->prints->contains(fn ($print) => $print->effectiveRequiresGraphic());

        if (!$requiresGraphic) {
            return [
                'status' => 'gerekli_degil',
                'status_label' => 'Grafik Gerekli Değil',
                'public_status_label' => 'Grafik gerekli değil',
                'approval_type' => null,
                'approval_status' => 'gerekli_degil',
                'approval_status_label' => 'Onay Gerekmiyor',
                'approved_by_name' => null,
                'designer_name' => null,
                'short_note' => 'Bu kalemde grafik operasyonu tenant baskı ayarına göre gerekli değil.',
                'revision_note' => null,
                'primary_visual_attachment_id' => null,
                'updated_at' => null,
            ];
        }

        return [
            'status' => 'bekliyor',
            'status_label' => 'Grafik Bekliyor',
            'public_status_label' => 'Grafik bekliyor',
            'approval_type' => null,
            'approval_status' => 'bekliyor',
            'approval_status_label' => 'Onay Bekliyor',
            'approved_by_name' => null,
            'designer_name' => null,
            'short_note' => null,
            'revision_note' => null,
            'primary_visual_attachment_id' => null,
            'updated_at' => null,
        ];
    }

    private function buildProductionSnapshot(OrderItem $item): array
    {
        if (!$item->has_print) {
            return [
                'status' => 'gerekli_degil',
                'status_label' => 'Üretim Gerekli Değil',
                'production_status' => 'gerekli_degil',
                'production_status_label' => 'Üretim Gerekli Değil',
                'production_type' => null,
                'production_type_label' => '-',
                'production_company_name' => '-',
                'planned_quantity' => 0,
                'completed_quantity' => 0,
                'remaining_quantity' => 0,
                'cliche_required' => false,
                'cliche_status' => null,
                'cliche_status_label' => null,
                'note' => null,
                'issue_note' => null,
                'qc_status' => 'gerekli_degil',
                'qc_status_label' => 'Kalite Kontrol Gerekli Değil',
                'qc_note' => null,
                'public_status_label' => 'Üretim gerekli değil',
                'setup_required' => false,
                'setup_summary' => $this->emptySetupSummary(),
                'updated_at' => null,
            ];
        }

        $requiresProduction = $item->prints->isEmpty()
            ? true
            : $item->prints->contains(fn ($print) => $print->effectiveRequiresProduction());

        if (!$requiresProduction) {
            return [
                'status' => 'gerekli_degil',
                'status_label' => 'Üretim Gerekli Değil',
                'production_status' => 'gerekli_degil',
                'production_status_label' => 'Üretim Gerekli Değil',
                'production_type' => null,
                'production_type_label' => '-',
                'production_company_name' => '-',
                'planned_quantity' => 0,
                'completed_quantity' => 0,
                'remaining_quantity' => 0,
                'cliche_required' => false,
                'cliche_status' => null,
                'cliche_status_label' => null,
                'note' => null,
                'issue_note' => null,
                'qc_status' => 'gerekli_degil',
                'qc_status_label' => 'Kalite Kontrol Gerekli Değil',
                'qc_note' => null,
                'public_status_label' => 'Üretim gerekli değil',
                'setup_required' => false,
                'setup_summary' => $this->emptySetupSummary(),
                'updated_at' => null,
            ];
        }

        $requirements = $this->promotionIntermediateElementPolicy->shouldRender()
            ? $item->prints
                ->filter(fn ($print) => $print->effectiveRequiresProduction())
                ->flatMap(fn ($print) => data_get($print->setupStatusSummary(), 'items', []))
                ->values()
                ->all()
            : [];
        $setupRequired = !empty($requirements);
        $readyCount = collect($requirements)->where('status', OrderItemPrintSetupRequirement::STATUS_READY)->count();
        $pendingCount = collect($requirements)->filter(fn ($row) => in_array(data_get($row, 'status'), [
            OrderItemPrintSetupRequirement::STATUS_PENDING,
            OrderItemPrintSetupRequirement::STATUS_REQUESTED,
        ], true))->count();

        return [
            'status' => 'bekliyor',
            'status_label' => 'Üretim Bekliyor',
            'production_status' => 'bekliyor',
            'production_status_label' => 'Üretim Bekliyor',
            'note' => null,
            'issue_note' => null,
            'qc_status' => 'bekliyor',
            'qc_status_label' => 'Kalite Kontrol Bekliyor',
            'qc_note' => null,
            'public_status_label' => 'Üretim bekliyor',
            'setup_required' => $setupRequired,
            'setup_summary' => $this->emptySetupSummary($requirements, $readyCount, $pendingCount),
            'updated_at' => null,
        ];
    }

    private function buildDeliverySnapshot(Order $order): array
    {
        $deliveryType = $order->delivery_type;

        return [
            'status' => 'teslimat_bekliyor',
            'status_label' => 'Teslimat Bekliyor',
            'delivery_status' => 'teslimat_bekliyor',
            'delivery_status_label' => 'Teslimat Bekliyor',
            'public_status_label' => 'Teslimat bekliyor',
            'delivery_method' => $this->normalizeDeliveryMethod($deliveryType),
            'delivery_method_label' => $deliveryType ?: null,
            'delivery_type' => $deliveryType,
            'carrier_type' => null,
            'carrier_name' => null,
            'tracking_number' => null,
            'recipient_name' => null,
            'delivery_document_no' => null,
            'delivery_note' => null,
            'delivered_at' => null,
            'planned_quantity' => 0,
            'delivered_quantity' => 0,
            'remaining_quantity' => 0,
            'package_count' => null,
            'units_per_package' => null,
            'packaged_quantity' => null,
            'package_type' => null,
            'package_type_label' => null,
            'package_note' => null,
            'photo_count' => 0,
            'document_count' => 0,
            'financial_warning' => 'odeme_bekliyor',
            'financial_warning_label' => 'Ödeme bekliyor',
            'updated_at' => null,
        ];
    }

    private function emptySetupSummary(array $items = [], int $readyCount = 0, int $pendingCount = 0): array
    {
        return [
            'required' => !empty($items),
            'total' => count($items),
            'ready_count' => $readyCount,
            'pending_count' => $pendingCount,
            'labels' => collect($items)->pluck('setup_type_label')->filter()->unique()->values()->all(),
            'items' => array_values($items),
        ];
    }

    private function normalizeDeliveryMethod(?string $deliveryType): ?string
    {
        $normalized = trim(\Illuminate\Support\Str::ascii(mb_strtolower((string) $deliveryType)));

        return match ($normalized) {
            '' => null,
            'kargo' => 'kargo',
            'kurye' => 'kurye',
            'elden', 'elden teslim' => 'elden',
            'ambar' => 'ambar',
            'musteri alacak', 'musteri_alacak', 'musteri teslim alacak' => 'musteri_alacak',
            default => 'diger',
        };
    }

    private function resolveVariantName(OrderItem $item, array $productSnapshot): string
    {
        $variantName = data_get($productSnapshot, 'variant_name')
            ?: $item->tenantCatalogProductVariant?->variant_name
            ?: $item->tenantCatalogProductVariant?->variant_color
            ?: $item->tenantCatalogProductVariant?->variant_size;

        if (filled($variantName)) {
            return trim((string) $variantName);
        }

        $baseName = trim((string) ($item->product_name ?? ''));
        $snapshotName = trim((string) data_get($productSnapshot, 'product_name', ''));

        if ($snapshotName !== '' && $baseName !== '' && mb_strtolower($snapshotName) !== mb_strtolower($baseName)) {
            $normalized = trim(str_ireplace($baseName, '', $snapshotName));

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '-';
    }
}
