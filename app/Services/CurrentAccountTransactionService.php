<?php

namespace App\Services;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CurrentAccountTransactionService
{
    public function createManualTransaction(CurrentAccount $account, array $data, User $user): CurrentAccountTransaction
    {
        $this->assertAccountIsTransactionEligible($account);

        $transactionType = (string) ($data['transaction_type'] ?? '');
        $amount = (float) ($data['amount'] ?? 0);
        $direction = CurrentAccountTransaction::inferredDirectionForType(
            $transactionType,
            isset($data['direction']) ? (string) $data['direction'] : null
        );
        $status = (string) ($data['status'] ?? CurrentAccountTransaction::STATUS_OPEN);
        $order = $this->resolveLinkedOrder($account->tenant_account_id, $data['order_id'] ?? null);
        $documentNumber = CurrentAccountTransaction::sanitizeMetaText($data['document_number'] ?? null);
        $description = CurrentAccountTransaction::sanitizeMetaText($data['description'] ?? null);
        $internalNote = CurrentAccountTransaction::sanitizeMetaText($data['internal_note'] ?? null);
        $paymentMethod = CurrentAccountTransaction::sanitizeMetaText($data['payment_method'] ?? null);

        if ($amount < 0.01) {
            throw ValidationException::withMessages([
                'amount' => 'Tutar 0,01 veya daha büyük olmalıdır.',
            ]);
        }

        if ($transactionType === '' || ! array_key_exists($transactionType, CurrentAccountTransaction::manualEntryTypeLabels())) {
            throw ValidationException::withMessages([
                'transaction_type' => 'Geçerli bir fiş türü seçin.',
            ]);
        }

        if (! array_key_exists($status, CurrentAccountTransaction::manualStatusLabels())) {
            throw ValidationException::withMessages([
                'status' => 'Geçerli bir durum seçin.',
            ]);
        }

        if (
            CurrentAccountTransaction::requiresManualDirection($transactionType)
            && ! in_array($direction, array_keys(CurrentAccountTransaction::directionLabels()), true)
        ) {
            throw ValidationException::withMessages([
                'direction' => 'Bu fiş türü için işlem yönü zorunludur.',
            ]);
        }

        return DB::transaction(function () use ($account, $data, $user, $amount): CurrentAccountTransaction {
            $transactionType = (string) $data['transaction_type'];
            $direction = CurrentAccountTransaction::inferredDirectionForType(
                $transactionType,
                isset($data['direction']) ? (string) $data['direction'] : null
            );
            $order = $this->resolveLinkedOrder($account->tenant_account_id, $data['order_id'] ?? null);

            return CurrentAccountTransaction::query()->create([
                'tenant_account_id' => $account->tenant_account_id,
                'current_account_id' => $account->id,
                'transaction_type' => $transactionType,
                'source_type' => CurrentAccountTransaction::SOURCE_TYPE_MANUAL,
                'source_id' => $order?->id,
                'direction' => $direction,
                'amount' => $amount,
                'currency' => (string) ($data['currency'] ?? $account->default_currency ?: 'TRY'),
                'transaction_date' => $data['transaction_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'description' => CurrentAccountTransaction::sanitizeMetaText($data['description'] ?? null),
                'status' => (string) ($data['status'] ?? CurrentAccountTransaction::STATUS_OPEN),
                'created_by' => $user->id,
                'meta_json' => [
                    'created_via' => 'manual_current_account_transaction',
                    'manual' => array_filter([
                        'document_number' => CurrentAccountTransaction::sanitizeMetaText($data['document_number'] ?? null),
                        'payment_method' => CurrentAccountTransaction::sanitizeMetaText($data['payment_method'] ?? null),
                        'internal_note' => CurrentAccountTransaction::sanitizeMetaText($data['internal_note'] ?? null),
                        'linked_order_id' => $order?->id,
                        'linked_order_number' => $order?->document_number,
                    ], fn ($value) => $value !== null && $value !== ''),
                ],
            ]);
        });
    }

    public function manualTransactionTypeOptions(CurrentAccount $account): array
    {
        $roleKeys = $account->relationLoaded('roles')
            ? $account->roles->pluck('role')->values()->all()
            : $account->roles()->pluck('role')->values()->all();

        $priority = [];

        if (in_array(CurrentAccountRole::ROLE_CUSTOMER, $roleKeys, true)) {
            $priority = array_merge($priority, [
                CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
                CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
                CurrentAccountTransaction::TYPE_REFUND,
            ]);
        }

        if (in_array(CurrentAccountRole::ROLE_SUPPLIER, $roleKeys, true)) {
            $priority = array_merge($priority, [
                CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
                CurrentAccountTransaction::TYPE_SUPPLIER_PAYMENT,
            ]);
        }

        if (in_array(CurrentAccountRole::ROLE_SUBCONTRACTOR, $roleKeys, true)) {
            $priority = array_merge($priority, [
                CurrentAccountTransaction::TYPE_SUBCONTRACTOR_DEBIT,
                CurrentAccountTransaction::TYPE_SUBCONTRACTOR_PAYMENT,
            ]);
        }

        if (in_array(CurrentAccountRole::ROLE_CARRIER, $roleKeys, true)) {
            $priority = array_merge($priority, [
                CurrentAccountTransaction::TYPE_CARRIER_DEBIT,
                CurrentAccountTransaction::TYPE_CARRIER_PAYMENT,
            ]);
        }

        $priority = array_values(array_unique(array_merge($priority, [
            CurrentAccountTransaction::TYPE_ADJUSTMENT,
            CurrentAccountTransaction::TYPE_OPENING_BALANCE,
        ])));

        $labels = CurrentAccountTransaction::manualEntryTypeLabels();
        $options = [];

        foreach ($priority as $type) {
            if (! isset($labels[$type])) {
                continue;
            }

            $options[$type] = $labels[$type];
        }

        return $options;
    }

    public function manualQuickActionDefaults(CurrentAccount $account): array
    {
        $roleKeys = $account->relationLoaded('roles')
            ? $account->roles->pluck('role')->values()->all()
            : $account->roles()->pluck('role')->values()->all();

        $debitType = CurrentAccountTransaction::TYPE_ADJUSTMENT;
        $paymentType = CurrentAccountTransaction::TYPE_ADJUSTMENT;

        if (in_array(CurrentAccountRole::ROLE_CUSTOMER, $roleKeys, true)) {
            $debitType = CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT;
            $paymentType = CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT;
        }

        foreach ([
            CurrentAccountRole::ROLE_SUPPLIER => [
                'debit' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
                'payment' => CurrentAccountTransaction::TYPE_SUPPLIER_PAYMENT,
            ],
            CurrentAccountRole::ROLE_SUBCONTRACTOR => [
                'debit' => CurrentAccountTransaction::TYPE_SUBCONTRACTOR_DEBIT,
                'payment' => CurrentAccountTransaction::TYPE_SUBCONTRACTOR_PAYMENT,
            ],
            CurrentAccountRole::ROLE_CARRIER => [
                'debit' => CurrentAccountTransaction::TYPE_CARRIER_DEBIT,
                'payment' => CurrentAccountTransaction::TYPE_CARRIER_PAYMENT,
            ],
        ] as $role => $types) {
            if (in_array($role, $roleKeys, true) && $paymentType === CurrentAccountTransaction::TYPE_ADJUSTMENT) {
                $paymentType = $types['payment'];
            }

            if (
                in_array($role, $roleKeys, true)
                && $debitType === CurrentAccountTransaction::TYPE_ADJUSTMENT
            ) {
                $debitType = $types['debit'];
            }
        }

        return [
            'debit' => $debitType,
            'payment' => $paymentType,
            'collection' => in_array(CurrentAccountRole::ROLE_CUSTOMER, $roleKeys, true)
                ? CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT
                : $paymentType,
        ];
    }

    public function manualFormDefaults(CurrentAccount $account, ?string $requestedType = null, array $oldInput = []): array
    {
        $quickDefaults = $this->manualQuickActionDefaults($account);
        $requestedType = (string) ($requestedType ?? ($oldInput['transaction_type'] ?? $quickDefaults['debit']));
        $transactionType = array_key_exists($requestedType, CurrentAccountTransaction::manualEntryTypeLabels())
            ? $requestedType
            : $quickDefaults['debit'];

        $statusDefault = in_array($transactionType, [
            CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
            CurrentAccountTransaction::TYPE_SUPPLIER_PAYMENT,
            CurrentAccountTransaction::TYPE_SUBCONTRACTOR_PAYMENT,
            CurrentAccountTransaction::TYPE_CARRIER_PAYMENT,
        ], true)
            ? CurrentAccountTransaction::STATUS_CLOSED
            : CurrentAccountTransaction::STATUS_OPEN;

        return [
            'transaction_type' => $transactionType,
            'direction' => $oldInput['direction'] ?? CurrentAccountTransaction::inferredDirectionForType($transactionType),
            'status' => $oldInput['status'] ?? $statusDefault,
            'currency' => $oldInput['currency'] ?? ($account->default_currency ?: 'TL'),
            'transaction_date' => $oldInput['transaction_date'] ?? now()->toDateString(),
            'due_date' => $oldInput['due_date'] ?? null,
            'document_number' => $oldInput['document_number'] ?? null,
            'payment_method' => $oldInput['payment_method'] ?? OrderPayment::METHOD_BANK_TRANSFER,
            'order_id' => $oldInput['order_id'] ?? null,
            'description' => $oldInput['description'] ?? null,
            'internal_note' => $oldInput['internal_note'] ?? null,
        ];
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

    private function resolveLinkedOrder(int $tenantId, mixed $orderId): ?Order
    {
        if (! is_numeric($orderId) || (int) $orderId <= 0) {
            return null;
        }

        $order = Order::query()
            ->where('tenant_account_id', $tenantId)
            ->find((int) $orderId);

        if (! $order) {
            throw ValidationException::withMessages([
                'order_id' => 'Seçilen sipariş bu tenant için bulunamadı.',
            ]);
        }

        return $order;
    }

    private function formatMoney(float $amount, string $currency): string
    {
        return number_format($amount, 2, ',', '.') . ' ' . $currency;
    }
}
