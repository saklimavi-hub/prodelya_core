<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormDelivery;
use Illuminate\Support\Collection;

class OrderShowSummaryService
{
    public function __construct(
        protected OrderListSummaryService $orderListSummaryService,
        protected FinanceSummaryService $financeSummaryService
    ) {
    }

    public function build(Order $order, bool $canViewFinancialData): array
    {
        $overview = $this->orderListSummaryService->buildRow($order, $canViewFinancialData);
        $finance = $canViewFinancialData ? $this->financeSummaryService->summarizeOrder($order) : null;

        return [
            'overview' => $overview,
            'finance' => $finance,
            'module_cards' => $this->buildModuleCards($order, $overview, $canViewFinancialData, $finance),
            'item_rows' => $this->buildItemRows($order, $canViewFinancialData),
        ];
    }

    private function buildModuleCards(Order $order, array $overview, bool $canViewFinancialData, ?array $finance): array
    {
        $workForm = $order->workForms->first();
        $procurement = $order->procurements->first();
        $production = $order->printProductions->first();
        $delivery = $order->deliveries->first();

        $cards = [
            [
                'title' => 'İş Formu',
                'status' => $workForm ? ($workForm->work_form_number ?: 'Hazır') : 'Henüz yok',
                'badge' => $workForm ? 'blue' : 'gray',
                'copy' => $workForm ? 'İş formu ekranını aç' : 'Sipariş için iş formu bulunamadı',
                'url' => $workForm ? route('admin.work-forms.show', $workForm) : null,
            ],
            [
                'title' => 'Grafik',
                'status' => $this->graphicModuleStatus($order->workForms),
                'badge' => $this->graphicModuleBadge($order->workForms),
                'copy' => 'Grafik ekranını aç',
                'url' => $workForm ? route('admin.graphics.show', $workForm) : null,
            ],
            [
                'title' => 'Tedarik',
                'status' => $procurement?->safeStatusLabel() ?: 'Kayıt yok',
                'badge' => $this->procurementBadge($procurement),
                'copy' => 'Tedarik ekranını aç',
                'url' => $procurement ? route('admin.procurements.show', $procurement) : null,
            ],
            [
                'title' => 'Üretim',
                'status' => $production?->safeStatusLabel() ?: 'Başlamadı',
                'badge' => $this->productionBadge($production),
                'copy' => 'Üretim ekranını aç',
                'url' => $production ? route('admin.productions.show', $production) : null,
            ],
            [
                'title' => 'Teslimat',
                'status' => $delivery?->safeStatusLabel() ?: 'Planlanacak',
                'badge' => $this->deliveryBadge($delivery),
                'copy' => 'Teslimat ekranını aç',
                'url' => $delivery ? route('admin.deliveries.show', $delivery) : null,
            ],
        ];

        if ($canViewFinancialData) {
            $cards[] = [
                'title' => 'Finans',
                'status' => $finance['payment_status_label'] ?? 'İncele',
                'badge' => $overview['payment_status_badge'] ?? 'gray',
                'copy' => 'Finans ekranını aç',
                'url' => route('admin.finance.show', $order),
            ];
        }

        return $cards;
    }

    private function buildItemRows(Order $order, bool $canViewFinancialData): Collection
    {
        return $order->items
            ->values()
            ->map(function (OrderItem $item, int $index) use ($canViewFinancialData): array {
                $sequence = $index + 1;
                $prints = $item->prints
                    ->filter(fn (OrderItemPrint $print) => filled($print->print_type) || filled($print->print_option) || (float) $print->print_total > 0)
                    ->values()
                    ->map(function (OrderItemPrint $print, int $printIndex) use ($item, $sequence): array {
                        $production = $print->production;

                        return [
                            'code' => $sequence . chr(97 + $printIndex),
                            'print_type' => $print->print_type,
                            'print_option' => $print->print_option,
                            'quantity' => $this->formatQuantity($print->print_quantity, $item->unit),
                            'production_status' => $production?->safeStatusLabel() ?: 'Başlamadı',
                            'note' => $print->note ?: $print->production_note,
                            'unit_price_label' => $this->money((float) $print->print_unit_price, $item->order?->currency),
                            'total_label' => $this->money((float) $print->print_total, $item->order?->currency),
                        ];
                    });

                return [
                    'sequence' => $sequence,
                    'product_name' => $item->product_name ?: '-',
                    'product_code' => $item->product_code ?: '-',
                    'quantity' => $this->formatQuantity($item->quantity, $item->unit),
                    'operation_status' => $this->itemOperationStatus($item),
                    'work_form_number' => $item->workForm?->work_form_number,
                    'work_form_url' => $item->workForm ? route('admin.work-forms.show', $item->workForm) : null,
                    'prints' => $prints,
                    'show_prices' => $canViewFinancialData,
                    'product_total_label' => $this->money((float) $item->line_total, $item->order?->currency),
                    'print_total_label' => $this->money((float) $item->print_total, $item->order?->currency),
                ];
            });
    }

    private function itemOperationStatus(OrderItem $item): string
    {
        if ($item->delivery && $item->delivery->isDelivered()) {
            return 'Teslim Edildi';
        }

        if ($item->delivery && $item->delivery->hasIssue()) {
            return 'Teslimat Sorunu';
        }

        if ($item->prints->contains(fn (OrderItemPrint $print) => $print->production && !$print->production->isCompleted())) {
            return 'Üretim Bekliyor';
        }

        if ($item->procurement && !$item->procurement->isNotRequired() && !$item->procurement->isFullyReceived()) {
            return $item->procurement->safeStatusLabel();
        }

        if ($item->workForm && !in_array((string) data_get($item->workForm->graphic_snapshot, 'status', ''), ['gerekli_degil', 'uretime_hazir'], true)) {
            return 'Grafik Bekliyor';
        }

        return 'Siparişi İzle';
    }

    private function graphicModuleStatus(Collection $workForms): string
    {
        if ($workForms->isEmpty()) {
            return 'Kayıt yok';
        }

        $hasPending = $workForms->contains(function (OrderItemWorkForm $workForm): bool {
            $status = (string) data_get($workForm->graphic_snapshot, 'status', '');

            return $status !== '' && !in_array($status, ['gerekli_degil', 'uretime_hazir'], true);
        });

        return $hasPending ? 'Grafik Bekliyor' : 'Hazır';
    }

    private function graphicModuleBadge(Collection $workForms): string
    {
        return $this->graphicModuleStatus($workForms) === 'Hazır' ? 'green' : 'amber';
    }

    private function procurementBadge(?OrderItemProcurement $procurement): string
    {
        if (!$procurement) {
            return 'gray';
        }

        return match ($procurement->procurement_status) {
            OrderItemProcurement::STATUS_FULLY_RECEIVED,
            OrderItemProcurement::STATUS_NOT_REQUIRED,
            OrderItemProcurement::STATUS_CUSTOMER_RECEIVED => 'green',
            OrderItemProcurement::STATUS_CANCELLED => 'red',
            OrderItemProcurement::STATUS_PARTIALLY_RECEIVED => 'blue',
            default => 'amber',
        };
    }

    private function productionBadge(?OrderItemPrintProduction $production): string
    {
        if (!$production) {
            return 'gray';
        }

        if ($production->isProblematic()) {
            return 'red';
        }

        if ($production->isCompleted()) {
            return 'green';
        }

        return 'blue';
    }

    private function deliveryBadge(?OrderItemWorkFormDelivery $delivery): string
    {
        if (!$delivery) {
            return 'gray';
        }

        if ($delivery->hasIssue()) {
            return 'red';
        }

        if ($delivery->isDelivered()) {
            return 'green';
        }

        return 'orange';
    }

    private function formatQuantity(mixed $quantity, ?string $unit = null): string
    {
        $formatted = number_format((float) $quantity, 2, ',', '.');
        $formatted = rtrim(rtrim($formatted, '0'), ',');

        return trim($formatted . ' ' . ($unit ?: ''));
    }

    private function money(float $amount, ?string $currency): string
    {
        return number_format($amount, 2, ',', '.') . ' ' . ($currency ?: 'TL');
    }
}
