<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormDelivery;
use App\Support\WorkFormActivityLabelResolver;
use Illuminate\Support\Collection;

class OrderShowSummaryService
{
    public function __construct(
        protected OrderListSummaryService $orderListSummaryService,
        protected FinanceSummaryService $financeSummaryService,
        protected OrderFinanceSummaryService $orderFinanceSummaryService,
        protected OrderDetailProcessDepthPresenter $orderDetailProcessDepthPresenter,
        protected WorkFormActivityLabelResolver $activityLabelResolver,
    ) {
    }

    public function build(Order $order, bool $canViewFinancialData): array
    {
        $order->loadMissing([
            'tenant',
            'items.prints',
            'workForms.orderItem',
            'workForms.attachments',
            'workForms.activityLogs.creator',
            'procurements.orderItem',
            'procurements.supplierRequestItems.request',
            'printProductions.orderItemPrint.orderItem',
            'printProductions.workForm.attachments',
            'deliveries.workForm.attachments',
        ]);

        $overview = $this->orderListSummaryService->buildRow($order, $canViewFinancialData);
        $finance = $canViewFinancialData ? $this->financeSummaryService->summarizeOrder($order) : null;
        $financeOverview = $canViewFinancialData
            ? $this->orderFinanceSummaryService->summarize($order->fresh([
                'customer.companyRoles',
                'payments',
                'procurements',
                'printProductions',
            ]))
            : null;
        $processDepth = $this->orderDetailProcessDepthPresenter->present($order, $overview);

        return [
            'overview' => array_merge($overview, [
                'process_depth' => $processDepth,
            ]),
            'finance' => $finance,
            'finance_overview' => $financeOverview,
            'module_cards' => $this->buildModuleCards($order, $overview, $canViewFinancialData, $finance),
            'item_rows' => $this->buildItemRows($order, $canViewFinancialData),
            'history_rows' => $this->buildHistoryRows($order),
        ];
    }

    private function buildHistoryRows(Order $order): Collection
    {
        return $order->workForms
            ->flatMap(fn (OrderItemWorkForm $form) => $form->activityLogs)
            ->sortByDesc('created_at')
            ->values()
            ->map(fn ($log) => [
                'created_at_label' => optional($log->created_at)->format('d.m.Y H:i') ?: '-',
                'created_at_short_label' => optional($log->created_at)->format('d.m H:i') ?: '-',
                'label' => $this->activityLabelResolver->title((string) $log->action_type),
                'note' => $log->note ?: 'İşlem kaydı',
            ]);
    }

    private function buildModuleCards(Order $order, array $overview, bool $canViewFinancialData, ?array $finance): array
    {
        $workForm = $order->workForms->first();
        $procurement = $order->procurements->first();
        $production = $order->printProductions->first();
        $delivery = $order->deliveries->first();
        $hasPrintProcess = $this->hasPrintProcess($order);
        $moduleStatuses = (array) data_get($overview, 'sticky_panel.module_statuses', []);

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
                'status' => (string) data_get($moduleStatuses, 'graphic.label', $this->graphicModuleStatus($order->workForms, $hasPrintProcess)),
                'badge' => (string) data_get($moduleStatuses, 'graphic.badge', $this->graphicModuleBadge($order->workForms, $hasPrintProcess)),
                'copy' => 'Grafik ekranını aç',
                'url' => $workForm ? route('admin.graphics.show', $workForm) : null,
            ],
            [
                'title' => 'Tedarik',
                'status' => (string) data_get($moduleStatuses, 'procurement.label', $procurement?->userFacingStatusLabel() ?: 'Gerekli Değil'),
                'badge' => (string) data_get($moduleStatuses, 'procurement.badge', $this->procurementBadge($procurement)),
                'copy' => 'Tedarik ekranını aç',
                'url' => $procurement ? route('admin.procurements.show', $procurement) : route('admin.procurements.index'),
            ],
            [
                'title' => 'Üretim',
                'status' => (string) data_get($moduleStatuses, 'production.label', $production?->safeStatusLabel() ?: ($hasPrintProcess ? 'Başlamadı' : 'Gerekli Değil')),
                'badge' => (string) data_get($moduleStatuses, 'production.badge', $this->productionBadge($production, $hasPrintProcess)),
                'copy' => 'Üretim ekranını aç',
                'url' => $production ? route('admin.productions.show', $production) : route('admin.productions.index'),
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
            return $item->procurement->userFacingStatusLabel();
        }

        if ($item->workForm && !in_array((string) data_get($item->workForm->graphic_snapshot, 'status', ''), ['gerekli_degil', 'uretime_hazir'], true)) {
            return 'Grafik Bekliyor';
        }

        return 'Siparişi İzle';
    }

    private function hasPrintProcess(Order $order): bool
    {
        return $order->items->contains(fn (OrderItem $item) => $item->prints->isNotEmpty());
    }

    private function graphicModuleStatus(Collection $workForms, bool $hasPrintProcess): string
    {
        if (!$hasPrintProcess) {
            return 'Gerekli Değil';
        }

        if ($workForms->isEmpty()) {
            return 'Grafik Bekliyor';
        }

        $hasPending = $workForms->contains(function (OrderItemWorkForm $workForm): bool {
            $status = (string) data_get($workForm->graphic_snapshot, 'status', '');

            return $status !== '' && !in_array($status, ['gerekli_degil', 'uretime_hazir'], true);
        });

        return $hasPending ? 'Grafik Bekliyor' : 'Hazır';
    }

    private function graphicModuleBadge(Collection $workForms, bool $hasPrintProcess): string
    {
        if (!$hasPrintProcess) {
            return 'gray';
        }

        return $this->graphicModuleStatus($workForms, $hasPrintProcess) === 'Hazır' ? 'green' : 'amber';
    }

    private function procurementBadge(?OrderItemProcurement $procurement): string
    {
        if (!$procurement) {
            return 'gray';
        }

        return match ($procurement->userFacingState()) {
            'received', 'no_need' => 'green',
            'cancelled' => 'red',
            'request_sent', 'partial_received' => 'blue',
            default => 'amber',
        };
    }

    private function productionBadge(?OrderItemPrintProduction $production, bool $hasPrintProcess): string
    {
        if (!$hasPrintProcess) {
            return 'gray';
        }

        if (!$production) {
            return 'blue';
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
