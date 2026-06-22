<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\OrderPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FinanceSummaryService
{
    public const STATUS_PAYMENT_PENDING = 'odeme_bekliyor';
    public const STATUS_PARTIAL_PAYMENT = 'kismi_odeme';
    public const STATUS_PAID = 'odendi';
    public const STATUS_OVERPAID = 'fazla_odeme';
    public const STATUS_DUE_PENDING = 'vade_bekliyor';
    public const STATUS_COLLECTION_WARNING = 'tahsilat_uyarisi';
    public const STATUS_CANCELLED = 'iptal';

    public function summarizeOrder(Order $order): array
    {
        $order->loadMissing(['customer', 'payments']);

        $activePayments = $this->activePayments($order);
        $actualFlowPayments = $activePayments->filter(fn (OrderPayment $payment) => $payment->paid_at !== null);
        $adjustments = $activePayments->filter(fn (OrderPayment $payment) => $payment->isAdjustment());

        $paidTotal = $this->sumAmounts($actualFlowPayments->filter(fn (OrderPayment $payment) => $payment->isCollection()));
        $refundedTotal = $this->sumAmounts($actualFlowPayments->filter(fn (OrderPayment $payment) => $payment->isRefund()));
        $adjustmentTotal = round($adjustments->sum(fn (OrderPayment $payment) => $payment->signedAmount()), 2);
        $netPaidTotal = round($paidTotal - $refundedTotal + $adjustmentTotal, 2);
        $grandTotal = $this->roundMoney($order->grand_total);
        $balanceDue = round($grandTotal - $netPaidTotal, 2);
        $nextDueDate = $this->nextDueDate($activePayments);
        $paymentStatus = $this->derivePaymentStatus(
            $order,
            $netPaidTotal,
            $balanceDue,
            $nextDueDate,
            $order->getRawOriginal('grand_total') !== null
        );
        $deliveryWarning = $this->deliveryFinancialWarningFromStatus($paymentStatus);

        return [
            'order_id' => $order->id,
            'order_number' => $order->document_number,
            'source_quote_number' => $order->source_quote_number,
            'customer_name' => $order->customer?->legal_name,
            'currency' => $order->currency ?: 'TL',
            'invoice_status' => $order->invoice_status,
            'invoice_status_label' => $this->invoiceStatusLabel($order->invoice_status),
            'product_total' => $this->roundMoney($order->product_total),
            'print_total' => $this->roundMoney($order->print_total),
            'subtotal' => $this->roundMoney($order->subtotal),
            'vat_total' => $this->roundMoney($order->vat_total),
            'grand_total' => $grandTotal,
            'vat_breakdown' => $this->normalizeVatBreakdown($order->vat_breakdown_json),
            'paid_total' => $paidTotal,
            'refunded_total' => $refundedTotal,
            'adjustment_total' => $adjustmentTotal,
            'net_paid_total' => $netPaidTotal,
            'balance_due' => $balanceDue,
            'payment_count' => $activePayments->count(),
            'last_payment_at' => optional(
                $actualFlowPayments->sortByDesc(fn (OrderPayment $payment) => optional($payment->paid_at)?->getTimestamp() ?? 0)->first()?->paid_at
            )?->toAtomString(),
            'next_due_date' => optional($nextDueDate)?->toAtomString(),
            'payment_status' => $paymentStatus,
            'payment_status_label' => $this->paymentStatusLabel($paymentStatus),
            'delivery_financial_warning' => $deliveryWarning,
            'delivery_financial_warning_label' => $this->deliveryWarningLabel($deliveryWarning),
        ];
    }

    public function summarizeOrders(Collection $orders): Collection
    {
        return $orders->map(fn ($order) => $this->summarizeOrder($order));
    }

    public function calculatePaidTotal(Order $order): float
    {
        return (float) data_get($this->summarizeOrder($order), 'net_paid_total', 0.0);
    }

    public function calculateBalanceDue(Order $order): float
    {
        return (float) data_get($this->summarizeOrder($order), 'balance_due', 0.0);
    }

    public function determinePaymentStatus(Order $order): string
    {
        $summary = $this->summarizeOrder($order);

        return (string) data_get($summary, 'payment_status', self::STATUS_PAYMENT_PENDING);
    }

    public function deliveryFinancialWarning(Order $order): string
    {
        $summary = $this->summarizeOrder($order);

        return (string) data_get($summary, 'delivery_financial_warning', OrderItemWorkFormDelivery::WARNING_NONE);
    }

    public function safeDeliveryFinancialWarningLabel(Order $order): string
    {
        $summary = $this->summarizeOrder($order);

        return (string) data_get($summary, 'delivery_financial_warning_label', 'Finans uyarısı yok');
    }

    private function activePayments(Order $order): Collection
    {
        return $order->payments
            ->filter(fn (OrderPayment $payment) => !$payment->isCancelled())
            ->values();
    }

    private function sumAmounts(Collection $payments): float
    {
        return round($payments->sum(fn (OrderPayment $payment) => abs((float) $payment->amount)), 2);
    }

    private function nextDueDate(Collection $payments): ?Carbon
    {
        /** @var OrderPayment|null $payment */
        $payment = $payments
            ->filter(fn (OrderPayment $row) => $row->due_date !== null && $row->paid_at === null)
            ->sortBy(fn (OrderPayment $row) => $row->due_date?->getTimestamp() ?? PHP_INT_MAX)
            ->first();

        return $payment?->due_date ? Carbon::parse($payment->due_date) : null;
    }

    private function derivePaymentStatus(
        Order $order,
        float $netPaidTotal,
        float $balanceDue,
        ?Carbon $nextDueDate,
        bool $hasGrandTotal
    ): string {
        if ($order->status === 'cancelled') {
            return self::STATUS_CANCELLED;
        }

        if (!$hasGrandTotal && $netPaidTotal <= 0.009) {
            if ($nextDueDate && $nextDueDate->lt(now())) {
                return self::STATUS_COLLECTION_WARNING;
            }

            if ($nextDueDate && $nextDueDate->gt(now())) {
                return self::STATUS_DUE_PENDING;
            }

            return self::STATUS_PAYMENT_PENDING;
        }

        if ($balanceDue < -0.009) {
            return self::STATUS_OVERPAID;
        }

        if (abs($balanceDue) <= 0.009) {
            return self::STATUS_PAID;
        }

        if ($nextDueDate && $nextDueDate->lt(now())) {
            return self::STATUS_COLLECTION_WARNING;
        }

        if ($netPaidTotal > 0.009) {
            return self::STATUS_PARTIAL_PAYMENT;
        }

        if ($nextDueDate && $nextDueDate->gt(now())) {
            return self::STATUS_DUE_PENDING;
        }

        return self::STATUS_PAYMENT_PENDING;
    }

    private function deliveryFinancialWarningFromStatus(string $status): string
    {
        return match ($status) {
            self::STATUS_PAID, self::STATUS_OVERPAID, self::STATUS_CANCELLED => OrderItemWorkFormDelivery::WARNING_NONE,
            self::STATUS_PAYMENT_PENDING => OrderItemWorkFormDelivery::WARNING_PAYMENT_PENDING,
            self::STATUS_PARTIAL_PAYMENT => OrderItemWorkFormDelivery::WARNING_BALANCE_DUE,
            self::STATUS_DUE_PENDING => OrderItemWorkFormDelivery::WARNING_CHECK_BEFORE_DELIVERY,
            self::STATUS_COLLECTION_WARNING => OrderItemWorkFormDelivery::WARNING_COLLECTION_APPROVAL,
            default => OrderItemWorkFormDelivery::WARNING_NONE,
        };
    }

    private function invoiceStatusLabel(?string $status): string
    {
        return match ($status) {
            'fatura' => 'Fatura',
            default => 'Fiş',
        };
    }

    private function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PAYMENT_PENDING => 'Ödeme Bekliyor',
            self::STATUS_PARTIAL_PAYMENT => 'Kısmi Ödeme',
            self::STATUS_PAID => 'Ödendi',
            self::STATUS_OVERPAID => 'Fazla Ödeme',
            self::STATUS_DUE_PENDING => 'Vade Bekliyor',
            self::STATUS_COLLECTION_WARNING => 'Tahsilat Uyarısı',
            self::STATUS_CANCELLED => 'İptal',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function deliveryWarningLabel(string $warning): string
    {
        return OrderItemWorkFormDelivery::financialWarningLabels()[$warning]
            ?? 'Finans uyarısı yok';
    }

    private function normalizeVatBreakdown(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(static function (array $slice): array {
            return [
                'rate' => round((float) ($slice['rate'] ?? 0), 2),
                'total' => round((float) ($slice['total'] ?? 0), 2),
                'scope' => (string) ($slice['scope'] ?? 'general'),
            ];
        }, $value));
    }

    private function roundMoney(mixed $amount): float
    {
        return round((float) ($amount ?? 0), 2);
    }
}
