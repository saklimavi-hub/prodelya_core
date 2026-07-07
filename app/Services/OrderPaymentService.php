<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\OrderPayment;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderPaymentService
{
    public function __construct(
        protected FinanceSummaryService $financeSummaryService,
        protected DeliveryDataBuilder $deliveryDataBuilder,
        protected OrderCurrentAccountDebitSyncService $orderCurrentAccountDebitSyncService,
        protected OrderPaymentCurrentAccountSyncService $orderPaymentCurrentAccountSyncService,
        protected NotificationEventService $notificationEventService,
    ) {
    }

    public function createPayment(Order $order, array $data, ?User $user = null): OrderPayment
    {
        $order->loadMissing(['payments', 'deliveries.workForm.attachments', 'customer']);

        $paymentType = $this->normalizePaymentType($data['payment_type'] ?? OrderPayment::TYPE_COLLECTION);
        $currency = (string) ($data['currency'] ?? $order->currency ?? 'TL');
        $amount = $this->normalizeAmount($data['amount'] ?? 0, $paymentType);

        $this->validateCurrency($order, $currency);

        $payment = DB::transaction(function () use ($order, $data, $user, $paymentType, $currency, $amount): OrderPayment {
            $payment = OrderPayment::query()->create([
                'tenant_account_id' => $order->tenant_account_id,
                'order_id' => $order->id,
                'customer_company_id' => $order->customer_company_id,
                'payment_type' => $paymentType,
                'amount' => $amount,
                'currency' => $currency,
                'payment_method' => $this->normalizePaymentMethod($data['payment_method'] ?? null),
                'payment_note' => $this->normalizeText($data['payment_note'] ?? null),
                'payment_reference' => $this->normalizeText($data['payment_reference'] ?? null),
                'paid_at' => $this->normalizeDateTime($data['paid_at'] ?? null),
                'due_date' => $this->normalizeDateTime($data['due_date'] ?? null),
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]);

            $this->orderCurrentAccountDebitSyncService->syncOrder(
                $order->fresh(['customer.companyRoles', 'payments']),
                $user
            );
            $this->orderPaymentCurrentAccountSyncService->syncPayment($payment->fresh(['order', 'customerCompany.companyRoles', 'creator', 'updater']));
            $this->syncDeliveryFinancialWarnings($order->fresh(['payments', 'deliveries.workForm.attachments']), $user);

            return $payment->fresh(['order', 'customerCompany']);
        });

        $this->dispatchPaymentNotificationSafely($payment, 'payment_received', $user);

        return $payment;
    }

    public function cancelPayment(OrderPayment $payment, ?User $user = null, ?string $reason = null): OrderPayment
    {
        if ($payment->isCancelled()) {
            throw new \InvalidArgumentException('Bu tahsilat kaydi zaten iptal edilmis.');
        }

        $payment = DB::transaction(function () use ($payment, $user, $reason): OrderPayment {
            $note = $payment->payment_note;
            $reason = $this->normalizeText($reason);

            if ($reason) {
                $note = trim(($note ? $note . PHP_EOL : '') . 'İptal: ' . $reason);
            }

            $payment->forceFill([
                'cancelled_at' => now(),
                'payment_note' => $note,
                'updated_by' => $user?->id,
            ])->save();

            $this->orderPaymentCurrentAccountSyncService->cancelForPayment(
                $payment->fresh(['order', 'customerCompany.companyRoles', 'creator', 'updater']),
                $reason ?: 'Sipariş tahsilatı iptal edildi.',
                $user
            );
            $this->orderCurrentAccountDebitSyncService->syncOrder(
                $payment->order->fresh(['customer.companyRoles', 'payments']),
                $user
            );
            $this->syncDeliveryFinancialWarnings($payment->order->fresh(['payments', 'deliveries.workForm.attachments']), $user);

            return $payment->fresh(['order', 'customerCompany']);
        });

        $this->dispatchPaymentNotificationSafely($payment, 'payment_cancelled', $user, $reason);

        return $payment;
    }

    public function validateCurrency(Order $order, string $currency): void
    {
        if (mb_strtoupper(trim($currency)) !== mb_strtoupper(trim((string) $order->currency))) {
            throw new \InvalidArgumentException('Payment currency must match order currency.');
        }
    }

    public function markOrderPaid(
        Order $order,
        ?User $user = null,
        ?string $paymentMethod = null,
        ?string $note = null
    ): ?OrderPayment {
        $summary = $this->financeSummaryService->summarizeOrder($order->fresh(['payments', 'deliveries.workForm.attachments', 'customer']));
        $balanceDue = round((float) data_get($summary, 'balance_due', 0), 2);
        $paymentStatus = (string) data_get($summary, 'payment_status', FinanceSummaryService::STATUS_PAYMENT_PENDING);

        if ($paymentStatus === FinanceSummaryService::STATUS_OVERPAID) {
            throw new \InvalidArgumentException('Sipariste fazla ödeme görünüyor; yeni tahsilat oluşturulmadı.');
        }

        if ($balanceDue <= 0.009) {
            return null;
        }

        return $this->createPayment($order, [
            'payment_type' => OrderPayment::TYPE_COLLECTION,
            'amount' => $balanceDue,
            'currency' => $order->currency ?: 'TL',
            'payment_method' => $paymentMethod ?: OrderPayment::METHOD_OTHER,
            'payment_note' => $this->normalizeText($note) ?: 'Ödendi işaretle işlemi ile oluşturuldu.',
            'paid_at' => now(),
        ], $user);
    }

    public function syncDeliveryFinancialWarnings(Order $order, ?User $user = null): void
    {
        $order->loadMissing(['payments', 'deliveries.workForm.attachments']);

        $warning = $this->financeSummaryService->deliveryFinancialWarning($order);

        foreach ($order->deliveries as $delivery) {
            $this->syncDeliveryRecord($delivery, $warning, $user);
        }
    }

    private function syncDeliveryRecord(OrderItemWorkFormDelivery $delivery, string $warning, ?User $user): void
    {
        $delivery->loadMissing(['workForm.attachments', 'order', 'orderItem']);

        $warningChanged = $delivery->financial_warning !== $warning;
        $delivery->financial_warning = $warning;
        $delivery->updated_by = $user?->id;

        $newRecordSnapshot = $this->deliveryDataBuilder->build($delivery->workForm, $delivery);
        $recordSnapshotChanged = $this->snapshotsDiffer($delivery->getOriginal('delivery_snapshot'), $newRecordSnapshot);

        if ($warningChanged || $recordSnapshotChanged) {
            $delivery->delivery_snapshot = $newRecordSnapshot;
            $delivery->save();
        }

        if (!$delivery->workForm) {
            return;
        }

        $workForm = $delivery->workForm->fresh(['attachments']);
        $newWorkFormSnapshot = $this->deliveryDataBuilder->buildWorkFormSnapshot($delivery->fresh(['workForm.attachments', 'order', 'orderItem']));

        if ($this->snapshotsDiffer($workForm->delivery_snapshot, $newWorkFormSnapshot)) {
            $workForm->forceFill([
                'delivery_snapshot' => $newWorkFormSnapshot,
                'version' => (int) $workForm->version + 1,
                'updated_by' => $user?->id,
            ])->save();
        }
    }

    private function normalizePaymentType(?string $paymentType): string
    {
        $paymentType = trim((string) $paymentType);

        return match ($paymentType) {
            OrderPayment::TYPE_COLLECTION,
            OrderPayment::TYPE_REFUND,
            OrderPayment::TYPE_ADJUSTMENT => $paymentType,
            default => OrderPayment::TYPE_COLLECTION,
        };
    }

    private function normalizePaymentMethod(?string $paymentMethod): ?string
    {
        $paymentMethod = $this->normalizeText($paymentMethod);

        if (!$paymentMethod) {
            return null;
        }

        return in_array($paymentMethod, [
            OrderPayment::METHOD_CASH,
            OrderPayment::METHOD_BANK_TRANSFER,
            OrderPayment::METHOD_CREDIT_CARD,
            OrderPayment::METHOD_CHEQUE,
            OrderPayment::METHOD_PROMISSORY,
            OrderPayment::METHOD_OTHER,
        ], true) ? $paymentMethod : OrderPayment::METHOD_OTHER;
    }

    private function normalizeAmount(mixed $amount, string $paymentType): float
    {
        $amount = round((float) $amount, 2);

        if ($paymentType === OrderPayment::TYPE_ADJUSTMENT) {
            if (abs($amount) <= 0.009) {
                throw new \InvalidArgumentException('Adjustment amount must be non-zero.');
            }

            return $amount;
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }

        return $amount;
    }

    private function normalizeDateTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        return Carbon::parse($value);
    }

    private function normalizeText(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function snapshotsDiffer(mixed $left, mixed $right): bool
    {
        return json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            !== json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function dispatchPaymentNotificationSafely(
        OrderPayment $payment,
        string $eventKey,
        ?User $user,
        ?string $note = null
    ): void {
        $payment->loadMissing(['tenant', 'order.customer.contacts', 'customerCompany', 'creator', 'updater']);

        $tenant = $payment->tenant;

        if (!$tenant) {
            return;
        }

        try {
            $this->notificationEventService->dispatchEvent(
                $tenant,
                $eventKey,
                $payment,
                [
                    'audience_type' => 'finance',
                    'channels' => ['internal', 'email'],
                    'created_by' => $user,
                    'related_type' => $payment->getMorphClass(),
                    'related_id' => $payment->id,
                    'context' => [
                        'status_label' => $payment->safePaymentTypeLabel(),
                        'payment_type_label' => $payment->safePaymentTypeLabel(),
                        'payment_method' => $payment->safePaymentMethodLabel(),
                        'payment_method_label' => $payment->safePaymentMethodLabel(),
                        'payment_reference' => $payment->payment_reference,
                        'paid_at' => optional($payment->paid_at)?->toAtomString(),
                        'due_date' => optional($payment->due_date)?->toAtomString(),
                        'internal_note' => $note,
                    ],
                ]
            );
        } catch (\Throwable) {
            // Payment and current account sync should not fail because of notifications.
        }
    }
}
