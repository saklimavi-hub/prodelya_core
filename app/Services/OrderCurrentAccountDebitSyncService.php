<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderCurrentAccountDebitSyncService
{
    public const SOURCE_TYPE = 'order';

    public function __construct(
        protected CurrentAccountSyncService $currentAccountSyncService,
        protected CurrentAccountTransactionService $transactionService,
        protected FinanceSummaryService $financeSummaryService,
    ) {
    }

    public function syncOrder(Order $order, ?User $user = null): ?CurrentAccountTransaction
    {
        $order->loadMissing(['customer.companyRoles', 'payments']);

        if ($order->document_type !== 'order') {
            return null;
        }

        $existing = $this->findExistingTransactionForOrder($order);

        if ($order->status === 'cancelled') {
            if ($existing && ! $existing->isCancelled()) {
                return $this->transactionService->cancelTransaction(
                    $existing,
                    'Sipariş iptal edildiği için müşteri borcu hareketi kapatıldı.',
                    $user ?? $order->creator ?? new User(['id' => null])
                );
            }

            return $existing;
        }

        $customer = $this->resolveCustomerCompany($order);
        $account = $this->resolveCurrentAccount($customer);
        $data = $this->mapOrderToTransactionData($order->fresh(['customer.companyRoles', 'payments']));

        return DB::transaction(function () use ($order, $account, $existing, $data): CurrentAccountTransaction {
            if ($existing) {
                $existing->forceFill([
                    'tenant_account_id' => $order->tenant_account_id,
                    'current_account_id' => $account->id,
                    'transaction_type' => $data['transaction_type'],
                    'direction' => $data['direction'],
                    'amount' => $data['amount'],
                    'currency' => $data['currency'],
                    'transaction_date' => $data['transaction_date'],
                    'due_date' => $data['due_date'],
                    'description' => $data['description'],
                    'status' => $data['status'],
                    'cancelled_at' => null,
                    'cancelled_by' => null,
                    'cancellation_reason' => null,
                    'meta_json' => array_merge($existing->meta_json ?? [], $data['meta_json'] ?? []),
                ])->save();

                return $existing->fresh(['currentAccount']);
            }

            return CurrentAccountTransaction::query()->create([
                'tenant_account_id' => $order->tenant_account_id,
                'current_account_id' => $account->id,
                'transaction_type' => $data['transaction_type'],
                'source_type' => self::SOURCE_TYPE,
                'source_id' => $order->id,
                'direction' => $data['direction'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'transaction_date' => $data['transaction_date'],
                'due_date' => $data['due_date'],
                'description' => $data['description'],
                'status' => $data['status'],
                'created_by' => $order->created_by,
                'meta_json' => $data['meta_json'],
            ])->fresh(['currentAccount']);
        });
    }

    public function findExistingTransactionForOrder(Order $order): ?CurrentAccountTransaction
    {
        return CurrentAccountTransaction::query()
            ->where('tenant_account_id', $order->tenant_account_id)
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $order->id)
            ->where('transaction_type', CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT)
            ->first();
    }

    public function resolveCurrentAccountForOrder(Order $order): ?CurrentAccount
    {
        if ($order->document_type !== 'order' || ! $order->customer_company_id) {
            return null;
        }

        $order->loadMissing('customer.companyRoles');

        if (! $order->customer || $order->customer->tenant_account_id !== $order->tenant_account_id) {
            return null;
        }

        $account = $this->currentAccountSyncService->ensureForCompany($order->customer);
        $this->currentAccountSyncService->ensureRole($account, CurrentAccountRole::ROLE_CUSTOMER);

        return $account->fresh(['roles', 'links']);
    }

    public function mapOrderToTransactionData(Order $order): array
    {
        $summary = $this->financeSummaryService->summarizeOrder($order);
        $paymentStatus = (string) ($summary['payment_status'] ?? FinanceSummaryService::STATUS_PAYMENT_PENDING);

        return [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => round((float) ($order->grand_total ?? 0), 2),
            'currency' => (string) ($order->currency ?: 'TL'),
            'transaction_date' => $this->resolveTransactionDate($order),
            'due_date' => $this->resolveDueDate($summary),
            'description' => 'Siparişten oluşan müşteri borcu',
            'status' => $this->mapPaymentStatusToTransactionStatus($paymentStatus),
            'meta_json' => [
                'auto_sync' => [
                    'synced_via' => 'order_current_account_debit_sync',
                    'order_id' => $order->id,
                    'order_number' => $order->document_number,
                    'source_quote_id' => $order->source_quote_id,
                    'source_quote_number' => $order->source_quote_number,
                    'payment_status' => $paymentStatus,
                    'last_synced_at' => now()->toAtomString(),
                ],
            ],
        ];
    }

    private function resolveCustomerCompany(Order $order): Company
    {
        if (! $order->customer_company_id) {
            throw new RuntimeException('Sipariş için müşteri cari kartı bulunamadı.');
        }

        if (! $order->customer || $order->customer->tenant_account_id !== $order->tenant_account_id) {
            throw new RuntimeException('Sipariş müşterisi bu tenant için geçerli değil.');
        }

        return $order->customer;
    }

    private function resolveCurrentAccount(Company $customer): CurrentAccount
    {
        $account = $this->currentAccountSyncService->ensureForCompany($customer);
        $this->currentAccountSyncService->ensureRole($account, CurrentAccountRole::ROLE_CUSTOMER);

        return $account;
    }

    private function resolveTransactionDate(Order $order): string
    {
        return optional($order->created_at ?: $order->quote_date ?: now())->toDateString();
    }

    private function resolveDueDate(array $summary): ?string
    {
        $nextDueDate = data_get($summary, 'next_due_date');

        if (! is_string($nextDueDate) || trim($nextDueDate) === '') {
            return null;
        }

        return Carbon::parse($nextDueDate)->toDateString();
    }

    private function mapPaymentStatusToTransactionStatus(string $paymentStatus): string
    {
        return match ($paymentStatus) {
            FinanceSummaryService::STATUS_PAID,
            FinanceSummaryService::STATUS_OVERPAID => CurrentAccountTransaction::STATUS_PAID,
            FinanceSummaryService::STATUS_PARTIAL_PAYMENT => CurrentAccountTransaction::STATUS_PARTIALLY_PAID,
            default => CurrentAccountTransaction::STATUS_OPEN,
        };
    }
}
