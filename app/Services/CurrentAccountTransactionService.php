<?php

namespace App\Services;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CurrentAccountTransactionService
{
    public function createManualTransaction(CurrentAccount $account, array $data, User $user): CurrentAccountTransaction
    {
        $this->assertAccountIsTransactionEligible($account);

        $amount = (float) ($data['amount'] ?? 0);

        if ($amount < 0.01) {
            throw ValidationException::withMessages([
                'amount' => 'Tutar 0,01 veya daha büyük olmalıdır.',
            ]);
        }

        return DB::transaction(function () use ($account, $data, $user, $amount): CurrentAccountTransaction {
            return CurrentAccountTransaction::query()->create([
                'tenant_account_id' => $account->tenant_account_id,
                'current_account_id' => $account->id,
                'transaction_type' => (string) $data['transaction_type'],
                'source_type' => 'manual',
                'source_id' => null,
                'direction' => (string) $data['direction'],
                'amount' => $amount,
                'currency' => (string) ($data['currency'] ?? 'TRY'),
                'transaction_date' => $data['transaction_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => (string) ($data['status'] ?? CurrentAccountTransaction::STATUS_OPEN),
                'created_by' => $user->id,
                'meta_json' => [
                    'created_via' => 'manual_current_account_transaction',
                ],
            ]);
        });
    }

    public function cancelTransaction(CurrentAccountTransaction $transaction, string $reason, User $user): CurrentAccountTransaction
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'cancellation_reason' => 'İptal nedeni zorunludur.',
            ]);
        }

        if ($transaction->isCancelled()) {
            return $transaction;
        }

        $transaction->forceFill([
            'status' => CurrentAccountTransaction::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
            'cancellation_reason' => trim($reason),
        ])->save();

        return $transaction->fresh();
    }

    public function getAccountSummary(CurrentAccount $account): array
    {
        $this->assertAccountRelationScope($account);

        $rows = $account->transactions()
            ->where('status', '!=', CurrentAccountTransaction::STATUS_CANCELLED)
            ->get();

        $currencies = [];

        foreach ($rows as $transaction) {
            $currency = (string) ($transaction->currency ?: 'TRY');

            if (!isset($currencies[$currency])) {
                $currencies[$currency] = [
                    'currency' => $currency,
                    'debit_total' => 0.0,
                    'credit_total' => 0.0,
                    'balance' => 0.0,
                    'transaction_count' => 0,
                ];
            }

            if ($transaction->isDebit()) {
                $currencies[$currency]['debit_total'] += (float) $transaction->amount;
            } else {
                $currencies[$currency]['credit_total'] += (float) $transaction->amount;
            }

            $currencies[$currency]['transaction_count']++;
        }

        foreach ($currencies as $currency => $summary) {
            $currencies[$currency]['debit_total'] = round($summary['debit_total'], 2);
            $currencies[$currency]['credit_total'] = round($summary['credit_total'], 2);
            $currencies[$currency]['balance'] = round($summary['debit_total'] - $summary['credit_total'], 2);
            $currencies[$currency]['debit_total_label'] = $this->formatMoney($currencies[$currency]['debit_total'], $currency);
            $currencies[$currency]['credit_total_label'] = $this->formatMoney($currencies[$currency]['credit_total'], $currency);
            $currencies[$currency]['balance_label'] = $this->formatMoney($currencies[$currency]['balance'], $currency);
        }

        return [
            'currencies' => $currencies,
            'transaction_count' => $rows->count(),
            'cancelled_count' => $account->transactions()->where('status', CurrentAccountTransaction::STATUS_CANCELLED)->count(),
        ];
    }

    public function getAccountTransactions(CurrentAccount $account, array $filters = []): HasMany
    {
        $this->assertAccountRelationScope($account);

        $query = $account->transactions()->with(['creator', 'canceller'])->latest('transaction_date')->latest('id');

        if (!empty($filters['transaction_type'])) {
            $query->where('transaction_type', (string) $filters['transaction_type']);
        }

        if (!empty($filters['direction'])) {
            $query->where('direction', (string) $filters['direction']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (!empty($filters['currency'])) {
            $query->where('currency', (string) $filters['currency']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('transaction_date', '>=', (string) $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('transaction_date', '<=', (string) $filters['date_to']);
        }

        return $query;
    }

    public function assertTenantScope(CurrentAccount $account, int $tenantId): void
    {
        if ($account->tenant_account_id !== $tenantId) {
            abort(403, 'Bu cari için tenant erişimi yok.');
        }
    }

    public function assertTransactionTenantScope(CurrentAccountTransaction $transaction, int $tenantId): void
    {
        if ($transaction->tenant_account_id !== $tenantId) {
            abort(403, 'Bu cari hareketi için tenant erişimi yok.');
        }
    }

    private function assertAccountIsTransactionEligible(CurrentAccount $account): void
    {
        if (in_array($account->status, [CurrentAccount::STATUS_BLOCKED, CurrentAccount::STATUS_ARCHIVED], true)) {
            throw ValidationException::withMessages([
                'account' => 'Blokeli veya arşivli cari için manuel hareket oluşturulamaz.',
            ]);
        }
    }

    private function assertAccountRelationScope(CurrentAccount $account): void
    {
        if (!$account->exists) {
            throw ValidationException::withMessages([
                'account' => 'Geçerli bir cari hesap bulunamadı.',
            ]);
        }
    }

    private function formatMoney(float $amount, string $currency): string
    {
        return number_format($amount, 2, ',', '.') . ' ' . $currency;
    }
}
