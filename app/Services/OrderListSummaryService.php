<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormDelivery;
use Illuminate\Support\Collection;

class OrderListSummaryService
{
    public function __construct(
        protected FinanceSummaryService $financeSummaryService
    ) {
    }

    public function buildRows(Collection $orders, bool $canViewFinancialData): Collection
    {
        return $orders
            ->map(fn (Order $order) => $this->buildRow($order, $canViewFinancialData))
            ->values();
    }

    public function filterRows(Collection $rows, string $status, bool $canViewFinancialData): Collection
    {
        if ($status === '' || $status === 'all') {
            return $rows->values();
        }

        return $rows
            ->filter(function (array $row) use ($status, $canViewFinancialData): bool {
                return match ($status) {
                    'open' => !$row['is_cancelled'] && !$row['is_completed'],
                    'in_operation' => !$row['is_cancelled'] && !$row['is_completed'] && $row['has_open_operation'],
                    'delivery_pending' => $row['is_delivery_pending'],
                    'payment_pending' => $canViewFinancialData && $row['is_payment_pending'],
                    'completed' => $row['is_completed'],
                    'problem' => $row['is_cancelled'] || $row['is_problematic'],
                    default => true,
                };
            })
            ->values();
    }

    public function buildRow(Order $order, bool $canViewFinancialData): array
    {
        /** @var Collection<int, OrderItemWorkForm> $workForms */
        $workForms = $order->workForms;
        /** @var Collection<int, OrderItemProcurement> $procurements */
        $procurements = $order->procurements;
        /** @var Collection<int, OrderItemPrintProduction> $productions */
        $productions = $order->printProductions;
        /** @var Collection<int, OrderItemWorkFormDelivery> $deliveries */
        $deliveries = $order->deliveries;

        $isCancelled = $this->isCancelled($order);
        $isCompleted = $this->isCompleted($order, $deliveries, $isCancelled);
        $graphicPending = $this->hasGraphicPending($workForms);
        $pendingProcurement = $this->firstPendingProcurement($procurements);
        $pendingProduction = $this->firstPendingProduction($productions);
        $deliveryIssue = $deliveries->first(fn (OrderItemWorkFormDelivery $delivery) => $delivery->hasIssue());
        $productionIssue = $productions->first(fn (OrderItemPrintProduction $production) => $production->isProblematic());
        $isProblematic = $deliveryIssue !== null || $productionIssue !== null || in_array((string) $order->status, ['failed', 'problematic'], true);
        $isDeliveryPending = !$isCancelled
            && !$isCompleted
            && $pendingProcurement === null
            && !$graphicPending
            && $pendingProduction === null
            && $this->hasPendingDelivery($deliveries);
        $hasOpenOperation = $graphicPending || $pendingProcurement !== null || $pendingProduction !== null || $isDeliveryPending;

        $financeSummary = $canViewFinancialData ? $this->financeSummaryService->summarizeOrder($order) : null;
        $isPaymentPending = $financeSummary !== null
            && (
                (float) ($financeSummary['balance_due'] ?? 0) > 0.009
                || in_array((string) ($financeSummary['payment_status'] ?? ''), [
                    FinanceSummaryService::STATUS_PAYMENT_PENDING,
                    FinanceSummaryService::STATUS_PARTIAL_PAYMENT,
                    FinanceSummaryService::STATUS_COLLECTION_WARNING,
                    FinanceSummaryService::STATUS_DUE_PENDING,
                ], true)
            );

        [$generalStatusLabel, $generalStatusBadge] = $this->generalStatus($isCancelled, $isProblematic, $isCompleted);
        [$operationStatusLabel, $operationStatusBadge] = $this->operationStatus(
            $isCancelled,
            $isProblematic,
            $isCompleted,
            $isDeliveryPending,
            $pendingProcurement,
            $pendingProduction,
            $graphicPending
        );

        $stickyPanel = [
            'order_id' => $order->id,
            'order_number' => $order->document_number,
            'customer_name' => $order->customer?->legal_name ?: ($order->customer?->name ?: '-'),
            'source_quote_number' => $order->source_quote_number ?: ($order->sourceQuote?->document_number ?: '-'),
            'delivery_date_label' => $order->valid_until ? $order->valid_until->format('d.m.Y') : '-',
            'general_status_label' => $generalStatusLabel,
            'general_status_badge' => $generalStatusBadge,
            'operation_status_label' => $operationStatusLabel,
            'operation_status_badge' => $operationStatusBadge,
            'next_action_label' => $this->nextAction(
                $isCancelled,
                $isProblematic,
                $isCompleted,
                $isDeliveryPending,
                $pendingProcurement,
                $pendingProduction,
                $graphicPending,
                $isPaymentPending,
                $canViewFinancialData
            ),
            'links' => [
                'show' => route('admin.orders.show', $order),
                'work_form' => $workForms->first() ? route('admin.work-forms.show', $workForms->first()) : null,
                'graphic' => $workForms->first() ? route('admin.graphics.show', $workForms->first()) : null,
                'procurement' => $procurements->first() ? route('admin.procurements.show', $procurements->first()) : null,
                'production' => $productions->first() ? route('admin.productions.show', $productions->first()) : null,
                'delivery' => $deliveries->first() ? route('admin.deliveries.show', $deliveries->first()) : null,
            ],
            'module_statuses' => [
                'graphic' => $this->stickyGraphicStatus($workForms),
                'procurement' => $this->stickyProcurementStatus($procurements),
                'production' => $this->stickyProductionStatus($productions),
                'delivery' => $this->stickyDeliveryStatus($deliveries),
            ],
        ];

        if ($canViewFinancialData) {
            $stickyPanel['links']['finance'] = route('admin.finance.show', $order);
            $stickyPanel['module_statuses']['finance'] = [
                'label' => $financeSummary['payment_status_label'] ?? 'İncele',
                'badge' => $this->paymentBadge((string) ($financeSummary['payment_status'] ?? '')),
            ];
            $stickyPanel['finance'] = [
                'grand_total_label' => $this->money((float) ($financeSummary['grand_total'] ?? 0), $order->currency),
                'paid_total_label' => $this->money((float) ($financeSummary['net_paid_total'] ?? 0), $order->currency),
                'balance_due_label' => $this->money((float) ($financeSummary['balance_due'] ?? 0), $order->currency),
                'payment_status_label' => $financeSummary['payment_status_label'] ?? '-',
                'payment_status_badge' => $this->paymentBadge((string) ($financeSummary['payment_status'] ?? '')),
            ];
        }

        return [
            'order' => $order,
            'customer_name' => $order->customer?->legal_name ?: ($order->customer?->name ?: '-'),
            'source_quote_number' => $order->source_quote_number ?: ($order->sourceQuote?->document_number ?: '-'),
            'order_date_label' => optional($order->created_at)->format('d.m.Y') ?: '-',
            'delivery_date_label' => $order->valid_until ? $order->valid_until->format('d.m.Y') : '-',
            'general_status_label' => $generalStatusLabel,
            'general_status_badge' => $generalStatusBadge,
            'operation_status_label' => $operationStatusLabel,
            'operation_status_badge' => $operationStatusBadge,
            'payment_status_label' => $financeSummary['payment_status_label'] ?? null,
            'payment_status_badge' => $this->paymentBadge((string) ($financeSummary['payment_status'] ?? '')),
            'balance_due_label' => $financeSummary ? $this->money((float) ($financeSummary['balance_due'] ?? 0), $order->currency) : null,
            'grand_total_label' => $financeSummary ? $this->money((float) ($financeSummary['grand_total'] ?? 0), $order->currency) : null,
            'next_action_label' => $this->nextAction(
                $isCancelled,
                $isProblematic,
                $isCompleted,
                $isDeliveryPending,
                $pendingProcurement,
                $pendingProduction,
                $graphicPending,
                $isPaymentPending,
                $canViewFinancialData
            ),
            'is_cancelled' => $isCancelled,
            'is_completed' => $isCompleted,
            'is_problematic' => $isProblematic,
            'has_open_operation' => $hasOpenOperation,
            'is_delivery_pending' => $isDeliveryPending,
            'is_payment_pending' => $isPaymentPending,
            'links' => [
                'show' => route('admin.orders.show', $order),
                'work_form' => $workForms->first() ? route('admin.work-forms.show', $workForms->first()) : null,
                'graphic' => $workForms->first() ? route('admin.graphics.show', $workForms->first()) : null,
                'procurement' => $procurements->first() ? route('admin.procurements.show', $procurements->first()) : null,
                'production' => $productions->first() ? route('admin.productions.show', $productions->first()) : null,
                'delivery' => $deliveries->first() ? route('admin.deliveries.show', $deliveries->first()) : null,
                'finance' => $canViewFinancialData ? route('admin.finance.show', $order) : null,
            ],
            'sticky_panel' => $stickyPanel,
        ];
    }

    private function stickyGraphicStatus(Collection $workForms): array
    {
        if ($workForms->isEmpty()) {
            return ['label' => 'Kayıt Yok', 'badge' => 'gray'];
        }

        return $this->hasGraphicPending($workForms)
            ? ['label' => 'Bekliyor', 'badge' => 'amber']
            : ['label' => 'Hazır', 'badge' => 'green'];
    }

    private function stickyProcurementStatus(Collection $procurements): array
    {
        $pending = $this->firstPendingProcurement($procurements);
        if ($pending) {
            return ['label' => 'Bekliyor', 'badge' => 'amber'];
        }

        if ($procurements->contains(fn (OrderItemProcurement $procurement) => $procurement->procurement_status === OrderItemProcurement::STATUS_CANCELLED)) {
            return ['label' => 'Problemli', 'badge' => 'red'];
        }

        if ($procurements->isNotEmpty()) {
            return ['label' => 'Hazır', 'badge' => 'green'];
        }

        return ['label' => 'Kayıt Yok', 'badge' => 'gray'];
    }

    private function stickyProductionStatus(Collection $productions): array
    {
        if ($productions->contains(fn (OrderItemPrintProduction $production) => $production->isProblematic())) {
            return ['label' => 'Problemli', 'badge' => 'red'];
        }

        $pending = $this->firstPendingProduction($productions);
        if ($pending) {
            return ['label' => $pending->production_status === OrderItemPrintProduction::STATUS_PENDING ? 'Başlamadı' : 'Devam Ediyor', 'badge' => 'blue'];
        }

        if ($productions->isNotEmpty()) {
            return ['label' => 'Tamamlandı', 'badge' => 'green'];
        }

        return ['label' => 'Kayıt Yok', 'badge' => 'gray'];
    }

    private function stickyDeliveryStatus(Collection $deliveries): array
    {
        $issue = $deliveries->first(fn (OrderItemWorkFormDelivery $delivery) => $delivery->hasIssue());
        if ($issue) {
            return ['label' => 'Problemli', 'badge' => 'red'];
        }

        if ($deliveries->isNotEmpty() && $deliveries->every(fn (OrderItemWorkFormDelivery $delivery) => $delivery->isDelivered() && !$delivery->hasIssue())) {
            return ['label' => 'Teslim Edildi', 'badge' => 'green'];
        }

        if ($deliveries->isNotEmpty()) {
            return ['label' => 'Bekliyor', 'badge' => 'orange'];
        }

        return ['label' => 'Planlanacak', 'badge' => 'gray'];
    }

    private function generalStatus(bool $isCancelled, bool $isProblematic, bool $isCompleted): array
    {
        if ($isCancelled) {
            return ['İptal', 'red'];
        }

        if ($isProblematic) {
            return ['Problemli', 'red'];
        }

        if ($isCompleted) {
            return ['Tamamlandı', 'green'];
        }

        return ['Açık Sipariş', 'blue'];
    }

    private function operationStatus(
        bool $isCancelled,
        bool $isProblematic,
        bool $isCompleted,
        bool $isDeliveryPending,
        ?OrderItemProcurement $pendingProcurement,
        ?OrderItemPrintProduction $pendingProduction,
        bool $graphicPending
    ): array {
        if ($isCancelled) {
            return ['İptal', 'red'];
        }

        if ($isProblematic) {
            return ['Problemli', 'red'];
        }

        if ($isCompleted) {
            return ['Tamamlandı', 'green'];
        }

        if ($isDeliveryPending) {
            return ['Teslimat Bekliyor', 'orange'];
        }

        if ($pendingProcurement) {
            return [$pendingProcurement->safeStatusLabel(), 'amber'];
        }

        if ($graphicPending) {
            return ['Grafik Bekliyor', 'amber'];
        }

        if ($pendingProduction) {
            return [match ($pendingProduction->production_status) {
                OrderItemPrintProduction::STATUS_PENDING => 'Üretim Bekliyor',
                OrderItemPrintProduction::STATUS_COMPLETED => 'Teslimat Bekliyor',
                default => 'Üretimde',
            }, 'blue'];
        }

        return ['Siparişi İncele', 'gray'];
    }

    private function nextAction(
        bool $isCancelled,
        bool $isProblematic,
        bool $isCompleted,
        bool $isDeliveryPending,
        ?OrderItemProcurement $pendingProcurement,
        ?OrderItemPrintProduction $pendingProduction,
        bool $graphicPending,
        bool $isPaymentPending,
        bool $canViewFinancialData
    ): string {
        if ($isCancelled || $isProblematic) {
            return 'Siparişi incele';
        }

        if ($isCompleted) {
            return $canViewFinancialData && $isPaymentPending
                ? 'Tahsilat bekliyor'
                : 'Sipariş tamamlandı';
        }

        if ($isDeliveryPending) {
            return 'Teslimat planla';
        }

        if ($pendingProcurement) {
            return 'Tedarik bekliyor';
        }

        if ($graphicPending) {
            return 'Grafik kontrol et';
        }

        if ($pendingProduction) {
            return 'Üretim bekliyor';
        }

        if ($canViewFinancialData && $isPaymentPending) {
            return 'Tahsilat bekliyor';
        }

        return 'Siparişi incele';
    }

    private function isCancelled(Order $order): bool
    {
        return in_array((string) $order->status, ['cancelled', 'iptal'], true);
    }

    private function isCompleted(Order $order, Collection $deliveries, bool $isCancelled): bool
    {
        if ($isCancelled) {
            return false;
        }

        if ($deliveries->isEmpty()) {
            return in_array((string) $order->status, ['completed', 'tamamlandi'], true);
        }

        return $deliveries->every(fn (OrderItemWorkFormDelivery $delivery) => $delivery->isDelivered() && !$delivery->hasIssue());
    }

    private function hasGraphicPending(Collection $workForms): bool
    {
        return $workForms->contains(function (OrderItemWorkForm $workForm): bool {
            $status = (string) data_get($workForm->graphic_snapshot, 'status', '');

            return $status !== ''
                && !in_array($status, ['gerekli_degil', 'uretime_hazir'], true);
        });
    }

    private function hasPendingDelivery(Collection $deliveries): bool
    {
        if ($deliveries->isEmpty()) {
            return false;
        }

        return $deliveries->contains(fn (OrderItemWorkFormDelivery $delivery) => !$delivery->hasIssue() && !$delivery->isDelivered());
    }

    private function firstPendingProcurement(Collection $procurements): ?OrderItemProcurement
    {
        /** @var OrderItemProcurement|null $row */
        $row = $procurements->first(function (OrderItemProcurement $procurement): bool {
            return !$procurement->isNotRequired()
                && !$procurement->isFullyReceived()
                && $procurement->procurement_status !== OrderItemProcurement::STATUS_CANCELLED;
        });

        return $row;
    }

    private function firstPendingProduction(Collection $productions): ?OrderItemPrintProduction
    {
        /** @var OrderItemPrintProduction|null $row */
        $row = $productions->first(function (OrderItemPrintProduction $production): bool {
            return !$production->isCompleted()
                && $production->production_status !== OrderItemPrintProduction::STATUS_CANCELLED;
        });

        return $row;
    }

    private function paymentBadge(string $status): string
    {
        return match ($status) {
            FinanceSummaryService::STATUS_PAID, FinanceSummaryService::STATUS_OVERPAID => 'green',
            FinanceSummaryService::STATUS_COLLECTION_WARNING => 'red',
            FinanceSummaryService::STATUS_PARTIAL_PAYMENT, FinanceSummaryService::STATUS_PAYMENT_PENDING => 'amber',
            FinanceSummaryService::STATUS_DUE_PENDING => 'orange',
            FinanceSummaryService::STATUS_CANCELLED => 'gray',
            default => 'gray',
        };
    }

    private function money(float $amount, ?string $currency): string
    {
        return number_format($amount, 2, ',', '.') . ' ' . ($currency ?: 'TL');
    }
}
