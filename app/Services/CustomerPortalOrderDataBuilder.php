<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItemWorkForm;
use Illuminate\Support\Str;

class CustomerPortalOrderDataBuilder
{
    public function __construct(
        private readonly CustomerFacingPriceDisplayService $customerFacingPriceDisplayService,
    ) {
    }

    public function buildListRow(Order $order): array
    {
        return [
            'id' => $order->id,
            'document_number' => $order->document_number,
            'order_date' => optional($order->created_at)->format('d.m.Y') ?: '-',
            'status_label' => $this->humanizeStatus((string) $order->status),
            'product_summary' => $this->productSummary($order),
            'operation_status' => $this->resolveOperationStatus($order),
            'delivery_status' => $this->resolveDeliveryStatus($order),
            'tracking_url' => $this->resolveTrackingUrl($order),
            'customer_message' => $this->customerFacingOrderMessage($order),
        ];
    }

    public function buildDetail(Order $order, array $trackingHelperUrls = []): array
    {
        return [
            'header' => [
                'document_number' => $order->document_number,
                'order_date' => optional($order->created_at)->format('d.m.Y') ?: '-',
                'status_label' => $this->humanizeStatus((string) $order->status),
                'company_name' => $order->customer?->legal_name ?: '-',
                'currency' => $order->currency ?: 'TL',
                'note' => $this->sanitizeVisibleNote($order->notes),
                'customer_message' => $this->customerFacingOrderMessage($order),
            ],
            'items' => $order->items->map(function ($item): array {
                $customerFacing = $this->customerFacingPriceDisplayService->buildItem(
                    $item,
                    $item->order?->currency ?: 'TL'
                );

                return [
                    'product_name' => $item->product_name ?: '-',
                    'product_code' => $item->product_code ?: null,
                    'quantity' => $this->formatQuantity($item->quantity, $item->unit),
                    'unit_price' => $customerFacing['customer_unit_price_label'],
                    'line_total' => $customerFacing['customer_line_total_label'],
                    'prints' => collect($customerFacing['prints'])->map(function (array $print): array {
                        return [
                            'label' => trim(collect([$print['print_type'] ?? null, $print['print_option'] ?? null])->filter()->implode(' ')),
                            'quantity' => $print['quantity_label'] ?? '-',
                            'unit_price' => $print['unit_price_label'] ?? null,
                            'line_total' => $print['total_label'] ?? null,
                            'show_price_details' => (bool) ($print['show_price_details'] ?? false),
                            'note' => $this->sanitizeVisibleNote($print['note'] ?? null),
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
            'totals' => [
                'subtotal' => $this->formatMoney($order->subtotal, $order->currency),
                'vat_total' => $this->formatMoney($order->vat_total, $order->currency),
                'grand_total' => $this->formatMoney($order->grand_total, $order->currency),
            ],
            'work_forms' => $order->workForms->map(function (OrderItemWorkForm $workForm) use ($trackingHelperUrls): array {
                return [
                    'id' => $workForm->id,
                    'work_form_number' => $workForm->work_form_number,
                    'product_name' => data_get($workForm->product_snapshot, 'product_name', '-'),
                    'graphic_status' => data_get($workForm->graphic_snapshot, 'public_status_label')
                        ?: data_get($workForm->graphic_snapshot, 'status_label')
                        ?: 'Grafik bekliyor',
                    'procurement_status' => data_get($workForm->procurement_snapshot, 'public_status_label') ?: 'Bekliyor',
                    'production_status' => data_get($workForm->production_snapshot, 'public_status_label') ?: 'Bekliyor',
                    'delivery_status' => data_get($workForm->delivery_snapshot, 'public_status_label') ?: 'Bekliyor',
                    'tracking_number' => $this->sanitizeTrackingNumber(data_get($workForm->delivery_snapshot, 'tracking_number')),
                    'tracking_url' => $trackingHelperUrls[$workForm->id] ?? null,
                    'tracking_label' => ($trackingHelperUrls[$workForm->id] ?? null) ? 'Müşteri Takip Ekranı' : null,
                    'customer_message' => $this->customerFacingWorkFormMessage($workForm),
                ];
            })->values()->all(),
        ];
    }

    private function productSummary(Order $order): string
    {
        $firstProductName = trim((string) $order->items->first()?->product_name);

        if ($firstProductName === '') {
            return 'Ürün özeti paylaşılacak.';
        }

        $remainingCount = max(0, $order->items->count() - 1);

        if ($remainingCount === 0) {
            return $firstProductName;
        }

        return $firstProductName . ' +' . $remainingCount . ' kalem';
    }

    private function resolveOperationStatus(Order $order): string
    {
        $workForm = $order->workForms->first();

        if (! $workForm) {
            return '-';
        }

        return data_get($workForm->production_snapshot, 'public_status_label')
            ?: data_get($workForm->graphic_snapshot, 'public_status_label')
            ?: data_get($workForm->procurement_snapshot, 'public_status_label')
            ?: $this->humanizeStatus((string) $workForm->status);
    }

    private function resolveDeliveryStatus(Order $order): string
    {
        $workForm = $order->workForms->first();

        if (! $workForm) {
            return '-';
        }

        return data_get($workForm->delivery_snapshot, 'public_status_label') ?: 'Bekliyor';
    }

    private function resolveTrackingUrl(Order $order): ?string
    {
        $workForm = $order->workForms->first();

        if (! $workForm || ! filled($workForm->public_tracking_token)) {
            return null;
        }

        return route('customer.portal.orders.tracking.open', [
            'order' => $order->id,
            'workForm' => $workForm->id,
        ]);
    }

    private function customerFacingOrderMessage(Order $order): string
    {
        $workForm = $order->workForms->first();

        if (! $workForm) {
            return 'Siparişiniz hazırlık aşamasında.';
        }

        return $this->customerFacingWorkFormMessage($workForm);
    }

    private function customerFacingWorkFormMessage(OrderItemWorkForm $workForm): string
    {
        $delivery = Str::lower((string) data_get($workForm->delivery_snapshot, 'public_status_label'));
        $production = Str::lower((string) data_get($workForm->production_snapshot, 'public_status_label'));
        $procurement = Str::lower((string) data_get($workForm->procurement_snapshot, 'public_status_label'));
        $graphic = Str::lower((string) data_get($workForm->graphic_snapshot, 'public_status_label'));

        return match (true) {
            Str::contains($delivery, 'teslim edildi') => 'Siparişiniz teslim edildi.',
            Str::contains($delivery, 'teslimat bekliyor') => 'Siparişiniz teslimat için hazırlanıyor.',
            Str::contains($production, 'devam') => 'Siparişiniz üretim aşamasında.',
            Str::contains($production, 'tamam') => 'Siparişiniz üretimden çıktı.',
            Str::contains($graphic, 'onay') => 'Grafik onayı bekleniyor.',
            Str::contains($graphic, 'hazır') => 'Grafik hazırlığı tamamlandı.',
            Str::contains($procurement, 'hazırlan') || Str::contains($procurement, 'bekliyor') => 'Siparişiniz hazırlanıyor.',
            default => 'Siparişiniz hazırlık aşamasında.',
        };
    }

    private function humanizeStatus(string $status): string
    {
        $normalized = trim($status);

        if ($normalized === '') {
            return '-';
        }

        return Str::headline(str_replace('_', ' ', $normalized));
    }

    private function formatQuantity(mixed $quantity, ?string $unit): string
    {
        $formatted = number_format((float) $quantity, 2, ',', '.');
        $formatted = rtrim(rtrim($formatted, '0'), ',');

        return trim($formatted . ' ' . ($unit ?: ''));
    }

    private function formatMoney(mixed $amount, ?string $currency): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return number_format((float) $amount, 2, ',', '.') . ' ' . ($currency ?: 'TL');
    }

    private function sanitizeTrackingNumber(?string $trackingNumber): ?string
    {
        $tracking = trim((string) $trackingNumber);

        if ($tracking === '') {
            return null;
        }

        foreach (['file_path', 'physical_path', 'storage/app', 'group_code', 'pdh_raw'] as $forbidden) {
            if (Str::contains(Str::lower($tracking), Str::lower($forbidden))) {
                return null;
            }
        }

        return $tracking;
    }

    private function sanitizeVisibleNote(?string $note): ?string
    {
        $text = trim((string) $note);

        if ($text === '') {
            return null;
        }

        foreach ([
            'purchase_total',
            'purchase_unit_price',
            'supplier_cost',
            'subcontractor_cost',
            'setup_cost',
            'profit',
            'margin',
            'group_code',
            'file_path',
            'physical_path',
            'internal_note',
            'notification_logs',
            'current_account_transactions',
            'smtp_password',
            'api_key',
            'pdh_raw',
            'finance_warning',
            'balance_due',
            'payment_amount',
        ] as $forbidden) {
            if (Str::contains(Str::lower($text), Str::lower($forbidden))) {
                return null;
            }
        }

        return $text;
    }
}
