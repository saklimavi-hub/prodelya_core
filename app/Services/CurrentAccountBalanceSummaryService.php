<?php

namespace App\Services;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CurrentAccountBalanceSummaryService
{
    private const OPEN_STATUSES = [
        CurrentAccountTransaction::STATUS_OPEN,
        CurrentAccountTransaction::STATUS_PARTIALLY_PAID,
    ];

    private const PAYABLE_DEBIT_TYPES = [
        CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
        CurrentAccountTransaction::TYPE_SUBCONTRACTOR_DEBIT,
        CurrentAccountTransaction::TYPE_CARRIER_DEBIT,
    ];

    private const PAYABLE_CREDIT_TYPES = [
        CurrentAccountTransaction::TYPE_SUPPLIER_PAYMENT,
        CurrentAccountTransaction::TYPE_SUBCONTRACTOR_PAYMENT,
        CurrentAccountTransaction::TYPE_CARRIER_PAYMENT,
    ];

    public function __construct(
        private readonly CurrentAccountLedgerDisplayService $ledgerDisplayService,
    ) {
    }

    public function summarizeAccounts(int $tenantId, array $accountIds): array
    {
        $accountIds = collect($accountIds)
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($accountIds === []) {
            return [];
        }

        $accounts = CurrentAccount::query()
            ->where('tenant_account_id', $tenantId)
            ->whereIn('id', $accountIds)
            ->with('roles')
            ->get()
            ->keyBy('id');

        $transactionsByAccount = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $tenantId)
            ->whereIn('current_account_id', $accounts->keys()->all())
            ->where(function ($query): void {
                $query
                    ->where('status', '!=', CurrentAccountTransaction::STATUS_CANCELLED)
                    ->orWhereNull('status');
            })
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->groupBy('current_account_id');

        $today = now()->startOfDay();
        $summaries = [];

        foreach ($accounts as $accountId => $account) {
            $transactions = $transactionsByAccount->get($accountId, collect());
            $currencies = $transactions
                ->pluck('currency')
                ->filter()
                ->map(fn ($currency) => strtoupper((string) $currency))
                ->unique()
                ->values();

            $hasMultipleCurrencies = $currencies->count() > 1;
            $currency = $currencies->count() === 1
                ? (string) $currencies->first()
                : (string) ($account->default_currency ?: '');

            $roleKeys = $account->roles->pluck('role')->values()->all();

            $debitTotal = 0.0;
            $creditTotal = 0.0;
            $semanticBalance = 0.0;
            $openTransactionCount = 0;
            $overdueTransactionCount = 0;
            $overdueAmount = 0.0;
            $overdueReceivableAmount = 0.0;
            $overduePayableAmount = 0.0;
            $lastTransactionAt = null;

            foreach ($transactions as $transaction) {
                $amount = round((float) $transaction->amount, 2);

                if ($transaction->isDebit()) {
                    $debitTotal += $amount;
                } else {
                    $creditTotal += $amount;
                }

                $semanticDelta = $this->semanticSignedAmount($transaction, $roleKeys);
                $semanticBalance += $semanticDelta;

                if ($this->isOpenTransaction($transaction)) {
                    $openTransactionCount++;

                    if ($transaction->due_date && $transaction->due_date->copy()->startOfDay()->lt($today)) {
                        $overdueTransactionCount++;
                        $overdueAmount += abs($semanticDelta) > 0 ? abs($semanticDelta) : $amount;

                        if ($semanticDelta > 0) {
                            $overdueReceivableAmount += $semanticDelta;
                        } elseif ($semanticDelta < 0) {
                            $overduePayableAmount += abs($semanticDelta);
                        }
                    }
                }

                $transactionDate = $transaction->transaction_date ?: $transaction->created_at;
                if ($transactionDate instanceof CarbonInterface && ($lastTransactionAt === null || $transactionDate->gt($lastTransactionAt))) {
                    $lastTransactionAt = $transactionDate;
                }
            }

            $rawBalance = $hasMultipleCurrencies ? null : round($semanticBalance, 2);
            $direction = $hasMultipleCurrencies
                ? 'mixed'
                : ($rawBalance > 0 ? 'receivable' : ($rawBalance < 0 ? 'payable' : 'closed'));

            $summaries[$accountId] = [
                'current_account_id' => $accountId,
                'role_keys' => $roleKeys,
                'currency' => $currency !== '' ? $currency : ($account->default_currency ?: 'TL'),
                'has_multiple_currencies' => $hasMultipleCurrencies,
                'has_transactions' => $transactions->isNotEmpty(),
                'transaction_count' => $transactions->count(),
                'total_debit' => round($debitTotal, 2),
                'total_credit' => round($creditTotal, 2),
                'balance' => $rawBalance,
                'balance_amount' => $rawBalance === null ? null : abs($rawBalance),
                'balance_direction' => $direction,
                'balance_direction_label' => $this->ledgerDisplayService->balanceStatusLabel($rawBalance, $hasMultipleCurrencies),
                'balance_display_tone' => $this->ledgerDisplayService->moneyDisplayTone($rawBalance, $hasMultipleCurrencies),
                'balance_display_class' => $this->ledgerDisplayService->moneyDisplayClass($rawBalance, $hasMultipleCurrencies),
                'open_transaction_count' => $openTransactionCount,
                'overdue_amount' => $hasMultipleCurrencies ? null : round($overdueAmount, 2),
                'overdue_transaction_count' => $overdueTransactionCount,
                'overdue_receivable_amount' => $hasMultipleCurrencies ? null : round($overdueReceivableAmount, 2),
                'overdue_payable_amount' => $hasMultipleCurrencies ? null : round($overduePayableAmount, 2),
                'last_transaction_at' => $lastTransactionAt,
                'last_transaction_label' => $lastTransactionAt?->format('d.m.Y'),
                'formatted_total_debit' => $this->formatAggregateAmount(round($debitTotal, 2), $currency ?: 'TL', $hasMultipleCurrencies),
                'formatted_total_credit' => $this->formatAggregateAmount(round($creditTotal, 2), $currency ?: 'TL', $hasMultipleCurrencies),
                'formatted_balance' => $this->ledgerDisplayService->formatSignedBalance($rawBalance, $currency ?: 'TL', $hasMultipleCurrencies),
                'formatted_overdue_amount' => $this->formatOverdueLabel($hasMultipleCurrencies ? null : round($overdueAmount, 2), $currency ?: 'TL', $overdueTransactionCount, $hasMultipleCurrencies),
            ];
        }

        return $summaries;
    }

    public function buildDashboard(array $summaries, string $defaultCurrency = 'TL'): array
    {
        $totalReceivable = 0.0;
        $totalPayable = 0.0;
        $overdueTotal = 0.0;
        $openTransactionCount = 0;
        $activeSummaryCount = 0;

        foreach ($summaries as $summary) {
            if (($summary['has_multiple_currencies'] ?? false) === true) {
                continue;
            }

            if (($summary['currency'] ?? $defaultCurrency) !== $defaultCurrency) {
                continue;
            }

            $activeSummaryCount++;
            $openTransactionCount += (int) ($summary['open_transaction_count'] ?? 0);
            $overdueTotal += (float) ($summary['overdue_amount'] ?? 0);

            if (($summary['balance_direction'] ?? null) === 'receivable') {
                $totalReceivable += (float) ($summary['balance_amount'] ?? 0);
            }

            if (($summary['balance_direction'] ?? null) === 'payable') {
                $totalPayable += (float) ($summary['balance_amount'] ?? 0);
            }
        }

        return [
            'currency' => $defaultCurrency,
            'active_summary_count' => $activeSummaryCount,
            'total_receivable' => round($totalReceivable, 2),
            'total_payable' => round($totalPayable, 2),
            'overdue_total' => round($overdueTotal, 2),
            'open_transaction_count' => $openTransactionCount,
            'formatted_total_receivable' => $this->formatMoney(round($totalReceivable, 2), $defaultCurrency),
            'formatted_total_payable' => $this->formatMoney(round($totalPayable, 2), $defaultCurrency),
            'formatted_overdue_total' => $this->formatMoney(round($overdueTotal, 2), $defaultCurrency),
        ];
    }

    public function getStatementQuery(CurrentAccount $account, array $filters = []): Builder
    {
        $this->assertAccountScope($account);

        $query = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $account->tenant_account_id)
            ->where('current_account_id', $account->id)
            ->with(['creator', 'canceller'])
            ->latest('transaction_date')
            ->latest('id');

        if (! empty($filters['from'])) {
            $query->whereDate('transaction_date', '>=', (string) $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('transaction_date', '<=', (string) $filters['to']);
        }

        if (! empty($filters['type'])) {
            $query->where('transaction_type', (string) $filters['type']);
        }

        if (! empty($filters['status'])) {
            match ((string) $filters['status']) {
                'open' => $query->whereIn('status', self::OPEN_STATUSES),
                'closed' => $query->whereIn('status', [
                    CurrentAccountTransaction::STATUS_PAID,
                    CurrentAccountTransaction::STATUS_CLOSED,
                ]),
                'overdue' => $query
                    ->whereIn('status', self::OPEN_STATUSES)
                    ->whereDate('due_date', '<', now()->toDateString()),
                default => null,
            };
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('description', 'like', '%' . $search . '%')
                    ->orWhere('meta_json', 'like', '%' . $search . '%');
            });
        }

        return $query;
    }

    public function summarizeFilteredTransactions(CurrentAccount $account, array $filters = []): array
    {
        $transactions = $this->getStatementQuery($account, $filters)
            ->where(function (Builder $query): void {
                $query
                    ->where('status', '!=', CurrentAccountTransaction::STATUS_CANCELLED)
                    ->orWhereNull('status');
            })
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $roleKeys = $account->relationLoaded('roles')
            ? $account->roles->pluck('role')->values()->all()
            : $account->roles()->pluck('role')->values()->all();

        $currency = $this->resolveStatementCurrency($transactions, $account);
        $hasMultipleCurrencies = $transactions
            ->pluck('currency')
            ->filter()
            ->map(fn ($value) => strtoupper((string) $value))
            ->unique()
            ->count() > 1;

        $debitTotal = 0.0;
        $creditTotal = 0.0;
        $semanticBalance = 0.0;

        foreach ($transactions as $transaction) {
            $amount = round((float) $transaction->amount, 2);

            if ($transaction->isDebit()) {
                $debitTotal += $amount;
            } else {
                $creditTotal += $amount;
            }

            $semanticBalance += $this->semanticSignedAmount($transaction, $roleKeys);
        }

        $rawBalance = $hasMultipleCurrencies ? null : round($semanticBalance, 2);
        $direction = $hasMultipleCurrencies
            ? 'mixed'
            : ($rawBalance > 0 ? 'receivable' : ($rawBalance < 0 ? 'payable' : 'closed'));

        return [
            'transaction_count' => $transactions->count(),
            'currency' => $currency,
            'has_multiple_currencies' => $hasMultipleCurrencies,
            'total_debit' => round($debitTotal, 2),
            'total_credit' => round($creditTotal, 2),
            'balance' => $rawBalance,
            'balance_direction' => $direction,
            'balance_direction_label' => $this->ledgerDisplayService->balanceStatusLabel($rawBalance, $hasMultipleCurrencies),
            'balance_display_tone' => $this->ledgerDisplayService->moneyDisplayTone($rawBalance, $hasMultipleCurrencies),
            'balance_display_class' => $this->ledgerDisplayService->moneyDisplayClass($rawBalance, $hasMultipleCurrencies),
            'formatted_total_debit' => $this->formatAggregateAmount(round($debitTotal, 2), $currency, $hasMultipleCurrencies),
            'formatted_total_credit' => $this->formatAggregateAmount(round($creditTotal, 2), $currency, $hasMultipleCurrencies),
            'formatted_balance' => $this->ledgerDisplayService->formatSignedBalance($rawBalance, $currency, $hasMultipleCurrencies),
        ];
    }

    public function getRunningBalanceMap(CurrentAccount $account): array
    {
        $this->assertAccountScope($account);

        $roleKeys = $account->relationLoaded('roles')
            ? $account->roles->pluck('role')->values()->all()
            : $account->roles()->pluck('role')->values()->all();

        $transactions = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $account->tenant_account_id)
            ->where('current_account_id', $account->id)
            ->where(function (Builder $query): void {
                $query
                    ->where('status', '!=', CurrentAccountTransaction::STATUS_CANCELLED)
                    ->orWhereNull('status');
            })
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $running = 0.0;
        $balances = [];

        foreach ($transactions as $transaction) {
            $running += $this->semanticSignedAmount($transaction, $roleKeys);
            $currency = (string) ($transaction->currency ?: ($account->default_currency ?: 'TL'));

            $balances[$transaction->id] = [
                'amount' => round($running, 2),
                'label' => $this->ledgerDisplayService->formatSignedBalance(round($running, 2), $currency),
                'display_tone' => $this->ledgerDisplayService->moneyDisplayTone(round($running, 2)),
                'display_class' => $this->ledgerDisplayService->moneyDisplayClass(round($running, 2)),
                'direction' => $running > 0 ? 'receivable' : ($running < 0 ? 'payable' : 'closed'),
                'direction_label' => $this->ledgerDisplayService->balanceStatusLabel(round($running, 2)),
            ];
        }

        return $balances;
    }

    public function signedAmountForTransaction(CurrentAccount $account, CurrentAccountTransaction $transaction): float
    {
        $this->assertAccountScope($account);

        $roleKeys = $account->relationLoaded('roles')
            ? $account->roles->pluck('role')->values()->all()
            : $account->roles()->pluck('role')->values()->all();

        return $this->semanticSignedAmount($transaction, $roleKeys);
    }

    public function buildAgingSummary(CurrentAccount $account): array
    {
        $this->assertAccountScope($account);

        $roleKeys = $account->relationLoaded('roles')
            ? $account->roles->pluck('role')->values()->all()
            : $account->roles()->pluck('role')->values()->all();

        $transactions = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $account->tenant_account_id)
            ->where('current_account_id', $account->id)
            ->whereIn('status', self::OPEN_STATUSES)
            ->where(function (Builder $query): void {
                $query
                    ->where('status', '!=', CurrentAccountTransaction::STATUS_CANCELLED)
                    ->orWhereNull('status');
            })
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $currency = $this->resolveStatementCurrency($transactions, $account);
        $today = now()->startOfDay();

        $buckets = [
            'current' => ['label' => 'Vadesi Geçmemiş', 'count' => 0, 'amount' => 0.0],
            'days_1_7' => ['label' => '1-7 Gün', 'count' => 0, 'amount' => 0.0],
            'days_8_30' => ['label' => '8-30 Gün', 'count' => 0, 'amount' => 0.0],
            'days_31_60' => ['label' => '31-60 Gün', 'count' => 0, 'amount' => 0.0],
            'days_60_plus' => ['label' => '60+ Gün', 'count' => 0, 'amount' => 0.0],
            'undated' => ['label' => 'Vadesiz / Tarih Yok', 'count' => 0, 'amount' => 0.0],
        ];

        foreach ($transactions as $transaction) {
            $signedAmount = $this->semanticSignedAmount($transaction, $roleKeys);
            $amount = abs($signedAmount) > 0 ? abs($signedAmount) : round((float) $transaction->amount, 2);

            if (! $transaction->due_date) {
                $buckets['undated']['count']++;
                $buckets['undated']['amount'] += $amount;
                continue;
            }

            $daysLate = $transaction->due_date->copy()->startOfDay()->diffInDays($today);

            if ($transaction->due_date->copy()->startOfDay()->gte($today)) {
                $bucketKey = 'current';
            } elseif ($daysLate <= 7) {
                $bucketKey = 'days_1_7';
            } elseif ($daysLate <= 30) {
                $bucketKey = 'days_8_30';
            } elseif ($daysLate <= 60) {
                $bucketKey = 'days_31_60';
            } else {
                $bucketKey = 'days_60_plus';
            }

            $buckets[$bucketKey]['count']++;
            $buckets[$bucketKey]['amount'] += $amount;
        }

        foreach ($buckets as $key => $bucket) {
            $buckets[$key]['amount'] = round($bucket['amount'], 2);
            $buckets[$key]['formatted_amount'] = $this->formatMoney(round($bucket['amount'], 2), $currency);
        }

        return [
            'currency' => $currency,
            'buckets' => $buckets,
        ];
    }

    private function semanticSignedAmount(CurrentAccountTransaction $transaction, array $roleKeys): float
    {
        $amount = round((float) $transaction->amount, 2);

        return match ($transaction->transaction_type) {
            CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT => $amount,
            CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT => -$amount,
            CurrentAccountTransaction::TYPE_REFUND => -$amount,
            CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            CurrentAccountTransaction::TYPE_SUBCONTRACTOR_DEBIT,
            CurrentAccountTransaction::TYPE_CARRIER_DEBIT => -$amount,
            CurrentAccountTransaction::TYPE_SUPPLIER_PAYMENT,
            CurrentAccountTransaction::TYPE_SUBCONTRACTOR_PAYMENT,
            CurrentAccountTransaction::TYPE_CARRIER_PAYMENT => $amount,
            default => $this->fallbackSignedAmount($transaction, $roleKeys, $amount),
        };
    }

    private function fallbackSignedAmount(CurrentAccountTransaction $transaction, array $roleKeys, float $amount): float
    {
        $hasCustomerRole = in_array(CurrentAccountRole::ROLE_CUSTOMER, $roleKeys, true);
        $hasPayableRole = collect([
            CurrentAccountRole::ROLE_SUPPLIER,
            CurrentAccountRole::ROLE_SUBCONTRACTOR,
            CurrentAccountRole::ROLE_CARRIER,
            CurrentAccountRole::ROLE_SERVICE_PROVIDER,
        ])->contains(fn ($role) => in_array($role, $roleKeys, true));

        if (!$hasCustomerRole && $hasPayableRole) {
            return $transaction->isDebit() ? -$amount : $amount;
        }

        return $transaction->isDebit() ? $amount : -$amount;
    }

    private function isOpenTransaction(CurrentAccountTransaction $transaction): bool
    {
        return in_array((string) $transaction->status, self::OPEN_STATUSES, true);
    }

    private function formatMoney(float $amount, string $currency): string
    {
        return number_format($amount, 2, ',', '.') . ' ' . $currency;
    }

    private function formatAggregateAmount(float $amount, string $currency, bool $hasMultipleCurrencies): string
    {
        if ($hasMultipleCurrencies) {
            return 'Çoklu para birimi';
        }

        return $this->formatMoney($amount, $currency);
    }

    private function formatOverdueLabel(?float $amount, string $currency, int $count, bool $hasMultipleCurrencies): string
    {
        if ($hasMultipleCurrencies) {
            return 'Çoklu para birimi';
        }

        if ($count <= 0 || $amount === null) {
            return 'Yok';
        }

        return $this->formatMoney($amount, $currency);
    }

    private function resolveStatementCurrency(Collection $transactions, CurrentAccount $account): string
    {
        $currencies = $transactions
            ->pluck('currency')
            ->filter()
            ->map(fn ($value) => strtoupper((string) $value))
            ->unique()
            ->values();

        if ($currencies->count() === 1) {
            return (string) $currencies->first();
        }

        return (string) ($account->default_currency ?: 'TL');
    }

    private function assertAccountScope(CurrentAccount $account): void
    {
        if (! $account->exists || (int) $account->tenant_account_id <= 0) {
            abort(404);
        }
    }
}
