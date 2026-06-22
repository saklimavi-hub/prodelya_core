<?php

namespace App\Services;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\OrderPayment;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderPaymentCurrentAccountSyncService
{
    public const SOURCE_TYPE = 'order_payment';

    public function __construct(
        protected CurrentAccountSyncService $currentAccountSyncService,
        protected CurrentAccountTransactionService $transactionService,
    ) {
    }

    public function syncPayment(OrderPayment $payment): ?CurrentAccountTransaction
    {
        $payment->loadMissing(['order', 'customerCompany.companyRoles']);

        if (!$payment->customerCompany || $payment->customerCompany->tenant_account_id !== $payment->tenant_account_id) {
            return null;
        }

        $account = $this->currentAccountSyncService->ensureForCompany($payment->customerCompany);
        $this->currentAccountSyncService->ensureRole($account, CurrentAccountRole::ROLE_CUSTOMER);

        $existing = $this->findExistingTransactionForPayment($payment);

        if ($payment->isCancelled()) {
            if ($existing && !$existing->isCancelled()) {
                return $this->transactionService->cancelTransaction(
                    $existing,
                    'Sipariş tahsilatı iptal edildi.',
                    $payment->updater ?: $payment->creator ?: new User(['id' => null])
                );
            }

            return $existing;
        }

        $data = $this->mapPaymentToTransactionData($payment);

        return DB::transaction(function () use ($payment, $account, $existing, $data): CurrentAccountTransaction {
            if ($existing) {
                $existing->forceFill([
                    'tenant_account_id' => $payment->tenant_account_id,
                    'current_account_id' => $account->id,
                    'transaction_type' => $data['transaction_type'],
                    'direction' => $data['direction'],
                    'amount' => $data['amount'],
                    'currency' => $data['currency'],
                    'transaction_date' => $data['transaction_date'],
                    'due_date' => $data['due_date'],
                    'description' => $data['description'],
                    'status' => CurrentAccountTransaction::STATUS_OPEN,
                    'cancelled_at' => null,
                    'cancelled_by' => null,
                    'cancellation_reason' => null,
                    'meta_json' => array_merge($existing->meta_json ?? [], $data['meta_json'] ?? []),
                ])->save();

                return $existing->fresh(['currentAccount']);
            }

            return CurrentAccountTransaction::query()->create([
                'tenant_account_id' => $payment->tenant_account_id,
                'current_account_id' => $account->id,
                'transaction_type' => $data['transaction_type'],
                'source_type' => self::SOURCE_TYPE,
                'source_id' => $payment->id,
                'direction' => $data['direction'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'transaction_date' => $data['transaction_date'],
                'due_date' => $data['due_date'],
                'description' => $data['description'],
                'status' => CurrentAccountTransaction::STATUS_OPEN,
                'created_by' => $payment->created_by,
                'meta_json' => $data['meta_json'] ?? [],
            ])->fresh(['currentAccount']);
        });
    }

    public function cancelForPayment(OrderPayment $payment, string $reason, ?User $user = null): void
    {
        $transaction = $this->findExistingTransactionForPayment($payment);

        if (!$transaction || $transaction->isCancelled()) {
            return;
        }

        $this->transactionService->cancelTransaction(
            $transaction,
            $reason !== '' ? $reason : 'Sipariş tahsilatı iptal edildi.',
            $user ?? $payment->updater ?? $payment->creator ?? new User(['id' => null])
        );
    }

    public function syncOrderPaymentsForTenant(TenantAccount $tenant, bool $dryRun = false, ?int $paymentId = null): array
    {
        $payments = OrderPayment::query()
            ->where('tenant_account_id', $tenant->id)
            ->when($paymentId, fn ($query) => $query->whereKey($paymentId))
            ->with(['order', 'customerCompany.companyRoles', 'creator', 'updater'])
            ->orderBy('id')
            ->get();

        $report = [
            'tenant_id' => $tenant->id,
            'payments' => $payments->count(),
            'created' => 0,
            'updated' => 0,
            'cancelled' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        foreach ($payments as $payment) {
            try {
                $existing = $this->findExistingTransactionForPayment($payment);

                if ($dryRun) {
                    if (!$payment->customerCompany) {
                        $report['skipped']++;
                    } elseif ($payment->isCancelled()) {
                        $report[$existing && !$existing->isCancelled() ? 'cancelled' : 'skipped']++;
                    } else {
                        $report[$existing ? 'updated' : 'created']++;
                    }

                    continue;
                }

                $result = $this->syncPayment($payment);

                if (!$result && !$payment->customerCompany) {
                    $report['skipped']++;
                    continue;
                }

                if ($payment->isCancelled()) {
                    $report['cancelled']++;
                } elseif ($existing) {
                    $report['updated']++;
                } else {
                    $report['created']++;
                }
            } catch (\Throwable) {
                $report['errors']++;
            }
        }

        return $report;
    }

    public function findExistingTransactionForPayment(OrderPayment $payment): ?CurrentAccountTransaction
    {
        return CurrentAccountTransaction::query()
            ->where('tenant_account_id', $payment->tenant_account_id)
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $payment->id)
            ->first();
    }

    public function mapPaymentToTransactionData(OrderPayment $payment): array
    {
        $payment->loadMissing('order');

        $amount = round(abs((float) $payment->amount), 2);
        $orderNumber = $payment->order?->document_number ?: ('Sipariş #' . $payment->order_id);

        return match ($payment->payment_type) {
            OrderPayment::TYPE_REFUND => [
                'transaction_type' => CurrentAccountTransaction::TYPE_REFUND,
                'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
                'amount' => $amount,
                'currency' => (string) $payment->currency,
                'transaction_date' => optional($payment->paid_at ?: $payment->created_at)?->toDateString(),
                'due_date' => optional($payment->due_date)?->toDateString(),
                'description' => 'Sipariş iadesi: ' . $orderNumber,
                'meta_json' => ['payment_type' => $payment->payment_type],
            ],
            OrderPayment::TYPE_ADJUSTMENT => [
                'transaction_type' => CurrentAccountTransaction::TYPE_ADJUSTMENT,
                'direction' => (float) $payment->amount < 0
                    ? CurrentAccountTransaction::DIRECTION_CREDIT
                    : CurrentAccountTransaction::DIRECTION_DEBIT,
                'amount' => $amount,
                'currency' => (string) $payment->currency,
                'transaction_date' => optional($payment->paid_at ?: $payment->created_at)?->toDateString(),
                'due_date' => optional($payment->due_date)?->toDateString(),
                'description' => 'Sipariş düzeltmesi: ' . $orderNumber,
                'meta_json' => ['payment_type' => $payment->payment_type],
            ],
            default => [
                'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
                'direction' => CurrentAccountTransaction::DIRECTION_CREDIT,
                'amount' => $amount,
                'currency' => (string) $payment->currency,
                'transaction_date' => optional($payment->paid_at ?: $payment->created_at)?->toDateString(),
                'due_date' => optional($payment->due_date)?->toDateString(),
                'description' => 'Sipariş tahsilatı: ' . $orderNumber,
                'meta_json' => ['payment_type' => $payment->payment_type],
            ],
        };
    }
}
