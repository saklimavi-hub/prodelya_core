<?php

namespace App\Services;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderPayment;
use App\Models\SupplierProcurementRequestItem;
use Illuminate\Support\Collection;

class OrderFinanceSummaryService
{
    public function __construct(
        protected FinanceSummaryService $financeSummaryService,
        protected OrderCurrentAccountDebitSyncService $orderCurrentAccountDebitSyncService,
    ) {
    }

    public function summarize(Order $order): array
    {
        $order->loadMissing([
            'customer',
            'customer.companyRoles',
            'payments',
            'procurements',
            'printProductions',
        ]);

        $customerSummary = $this->buildCustomerReceivable($order);
        $supplierDebts = $this->buildSupplierDebts($order);
        $subcontractorDebts = $this->buildSubcontractorDebts($order);

        return [
            'order' => $order,
            'customer' => $order->customer,
            'customer_receivable' => $customerSummary,
            'supplier_debts' => $supplierDebts,
            'subcontractor_debts' => $subcontractorDebts,
            'overall' => $this->buildOverallStatus($order, $customerSummary, $supplierDebts, $subcontractorDebts),
            'movements' => $this->buildMovements($order, $customerSummary, $supplierDebts, $subcontractorDebts),
        ];
    }

    private function buildCustomerReceivable(Order $order): array
    {
        $summary = $this->financeSummaryService->summarizeOrder($order);
        $debitTransaction = $this->orderCurrentAccountDebitSyncService->findExistingTransactionForOrder($order);
        $currentAccount = $this->orderCurrentAccountDebitSyncService->resolveCurrentAccountForOrder($order);
        $paymentTransactions = $order->payments
            ->sortByDesc(fn (OrderPayment $payment): int => optional($payment->paid_at ?? $payment->created_at)?->getTimestamp() ?? 0)
            ->values()
            ->map(function (OrderPayment $payment) use ($order): array {
                return [
                    'payment' => $payment,
                    'label' => $payment->safePaymentTypeLabel(),
                    'amount' => round((float) $payment->signedAmount(), 2),
                    'formatted_amount' => $this->money((float) $payment->signedAmount(), $payment->currency ?: ($order->currency ?: 'TL')),
                    'date_label' => optional($payment->paid_at ?? $payment->created_at)?->format('d.m.Y H:i') ?: '-',
                    'status_label' => $payment->isCancelled()
                        ? 'İptal edildi'
                        : ($payment->paid_at ? 'İşlendi' : 'Bekliyor'),
                    'status_tone' => $payment->isCancelled()
                        ? 'gray'
                        : ($payment->paid_at ? 'green' : 'amber'),
                    'detail_url' => route('admin.finance.show', $order) . '#tahsilat-' . $payment->id,
                    'cancel_url' => !$payment->isCancelled()
                        ? route('admin.finance.payments.cancel', ['order' => $order, 'payment' => $payment])
                        : null,
                ];
            });

        $orderTotal = round((float) ($summary['grand_total'] ?? $order->grand_total ?? 0), 2);
        $collectedAmount = round((float) ($summary['net_paid_total'] ?? 0), 2);
        $remainingAmount = round((float) ($summary['balance_due'] ?? 0), 2);
        $debitAmount = $debitTransaction
            ? round((float) $debitTransaction->amount, 2)
            : $orderTotal;

        return [
            'order_total' => $orderTotal,
            'formatted_order_total' => $this->money($orderTotal, $order->currency ?: 'TL'),
            'debit_amount' => $debitAmount,
            'formatted_debit_amount' => $this->money($debitAmount, $order->currency ?: 'TL'),
            'collected_amount' => $collectedAmount,
            'formatted_collected_amount' => $this->money($collectedAmount, $order->currency ?: 'TL'),
            'remaining_amount' => $remainingAmount,
            'formatted_remaining_amount' => $this->money($remainingAmount, $order->currency ?: 'TL'),
            'status' => $this->customerReceivableStatus($order, $debitTransaction, $collectedAmount, $remainingAmount),
            'status_label' => $this->customerReceivableStatusLabel($order, $debitTransaction, $collectedAmount, $remainingAmount),
            'status_tone' => $this->customerReceivableStatusTone($order, $debitTransaction, $collectedAmount, $remainingAmount),
            'debit_transaction' => $debitTransaction,
            'payment_transactions' => $paymentTransactions,
            'current_account' => $currentAccount,
            'current_account_url' => $currentAccount ? route('admin.current-accounts.transactions.index', $currentAccount) : null,
            'collection_url' => route('admin.finance.show', $order) . '#tahsilat-formu',
            'order_url' => route('admin.orders.show', $order),
            'has_missing_debit' => $debitTransaction === null && $order->status !== 'cancelled',
            'missing_message' => $debitTransaction === null && $order->status !== 'cancelled'
                ? 'Sipariş müşteri borcu hareketi bulunamadı.'
                : null,
        ];
    }

    private function buildSupplierDebts(Order $order): array
    {
        $items = SupplierProcurementRequestItem::query()
            ->where('tenant_account_id', $order->tenant_account_id)
            ->where('order_id', $order->id)
            ->with([
                'request.supplier',
                'procurement',
            ])
            ->orderBy('id')
            ->get();

        $transactions = CurrentAccountTransaction::query()
            ->with('currentAccount')
            ->where('tenant_account_id', $order->tenant_account_id)
            ->where('source_type', SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE)
            ->whereIn('source_id', $items->pluck('id'))
            ->where('transaction_type', CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT)
            ->get()
            ->keyBy('source_id');

        $rows = $items->map(function (SupplierProcurementRequestItem $item) use ($transactions): array {
            /** @var CurrentAccountTransaction|null $transaction */
            $transaction = $transactions->get($item->id);
            $debtAmount = $transaction && !$transaction->isCancelled()
                ? round((float) $transaction->amount, 2)
                : round((float) ($item->purchase_total ?? 0), 2);
            $hasDebt = $debtAmount > 0.009;

            return [
                'type' => 'supplier',
                'source_item' => $item,
                'transaction' => $transaction,
                'supplier_name' => $item->request?->supplier?->name ?: 'Tedarikçi',
                'source_label' => $item->request?->request_number ?: ('Talep #' . $item->supplier_procurement_request_id),
                'debt_amount' => $debtAmount,
                'formatted_debt_amount' => $this->money($debtAmount, $transaction?->currency ?: 'TL'),
                'paid_amount' => null,
                'formatted_paid_amount' => 'Cari ekstreden takip edilir',
                'remaining_amount' => null,
                'formatted_remaining_amount' => 'Cari ekstreden takip edilir',
                'status' => $hasDebt ? 'payment_tracking_required' : 'none',
                'status_label' => $hasDebt ? 'Ödeme bekliyor' : 'Karşı borç yok',
                'status_tone' => $hasDebt ? 'amber' : 'gray',
                'has_reliable_payment_links' => false,
                'tracking_note' => $hasDebt ? 'Bağlı ödeme bulunamadı. Cari ekstreden takip edilir.' : null,
                'source_url' => $item->procurement ? route('admin.procurements.show', $item->procurement) : null,
                'current_account_url' => $transaction?->currentAccount
                    ? route('admin.current-accounts.transactions.index', $transaction->currentAccount)
                    : null,
                'action_label' => 'Tedarik Kaydını Aç',
            ];
        })->filter(fn (array $row): bool => $row['debt_amount'] > 0.009)->values();

        return $this->buildCounterpartyGroup(
            $rows,
            'Toplam Tedarik Borcu',
            'Tedarikçi borcu yok'
        );
    }

    private function buildSubcontractorDebts(Order $order): array
    {
        $productions = OrderItemPrintProduction::query()
            ->where('tenant_account_id', $order->tenant_account_id)
            ->where('order_id', $order->id)
            ->with([
                'productionCompany',
                'orderItem',
                'orderItemPrint',
            ])
            ->orderBy('id')
            ->get();

        $transactions = CurrentAccountTransaction::query()
            ->with('currentAccount')
            ->where('tenant_account_id', $order->tenant_account_id)
            ->where('source_type', SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE)
            ->whereIn('source_id', $productions->pluck('id'))
            ->where('transaction_type', CurrentAccountTransaction::TYPE_SUBCONTRACTOR_DEBIT)
            ->get()
            ->keyBy('source_id');

        $rows = $productions->map(function (OrderItemPrintProduction $production) use ($transactions): array {
            /** @var CurrentAccountTransaction|null $transaction */
            $transaction = $transactions->get($production->id);
            $debtAmount = $transaction && !$transaction->isCancelled()
                ? round((float) $transaction->amount, 2)
                : round((float) ($production->subcontractor_cost ?? 0), 2);
            $hasDebt = $debtAmount > 0.009;
            $sourceLabel = trim(implode(' / ', array_filter([
                $production->orderItemPrint?->sequence_code,
                $production->orderItemPrint?->print_type,
                $production->orderItem?->product_name,
            ])));

            return [
                'type' => 'subcontractor',
                'source_item' => $production,
                'transaction' => $transaction,
                'supplier_name' => $production->productionCompany?->legal_name ?: 'Fasoncu',
                'source_label' => $sourceLabel !== '' ? $sourceLabel : ('Üretim #' . $production->id),
                'debt_amount' => $debtAmount,
                'formatted_debt_amount' => $this->money($debtAmount, $transaction?->currency ?: ($production->subcontractor_cost_currency ?: 'TL')),
                'paid_amount' => null,
                'formatted_paid_amount' => 'Cari ekstreden takip edilir',
                'remaining_amount' => null,
                'formatted_remaining_amount' => 'Cari ekstreden takip edilir',
                'status' => $hasDebt ? 'payment_tracking_required' : 'none',
                'status_label' => $hasDebt ? 'Ödeme bekliyor' : 'Karşı borç yok',
                'status_tone' => $hasDebt ? 'amber' : 'gray',
                'has_reliable_payment_links' => false,
                'tracking_note' => $hasDebt ? 'Bağlı ödeme bulunamadı. Cari ekstreden takip edilir.' : null,
                'source_url' => route('admin.productions.show', $production),
                'current_account_url' => $transaction?->currentAccount
                    ? route('admin.current-accounts.transactions.index', $transaction->currentAccount)
                    : null,
                'action_label' => 'Üretim Kaydını Aç',
            ];
        })->filter(fn (array $row): bool => $row['debt_amount'] > 0.009)->values();

        return $this->buildCounterpartyGroup(
            $rows,
            'Toplam Fason Borcu',
            'Fason borcu yok'
        );
    }

    private function buildCounterpartyGroup(Collection $rows, string $title, string $emptyLabel): array
    {
        $totalDebt = round($rows->sum('debt_amount'), 2);
        $hasRows = $rows->isNotEmpty();
        $hasReliablePaymentLinks = $rows->contains(fn (array $row): bool => $row['has_reliable_payment_links'] === true);

        return [
            'title' => $title,
            'total_debt' => $totalDebt,
            'formatted_total_debt' => $this->money($totalDebt),
            'paid_amount' => $hasReliablePaymentLinks ? round($rows->sum('paid_amount'), 2) : null,
            'formatted_paid_amount' => $hasReliablePaymentLinks
                ? $this->money((float) $rows->sum('paid_amount'))
                : ($hasRows ? 'Bağlı ödeme bulunamadı' : '0,00 TL'),
            'remaining_amount' => $hasReliablePaymentLinks ? round($rows->sum('remaining_amount'), 2) : null,
            'formatted_remaining_amount' => $hasReliablePaymentLinks
                ? $this->money((float) $rows->sum('remaining_amount'))
                : ($hasRows ? 'Cari ekstreden takip edilir' : '0,00 TL'),
            'open_party_count' => $rows->pluck('supplier_name')->filter()->unique()->count(),
            'items' => $rows->values(),
            'status' => $hasRows ? 'payment_tracking_required' : 'none',
            'status_label' => $hasRows ? 'Ödeme bekliyor' : 'Karşı borç yok',
            'status_tone' => $hasRows ? 'amber' : 'gray',
            'has_reliable_payment_links' => $hasReliablePaymentLinks,
            'has_untracked_payments' => $hasRows && !$hasReliablePaymentLinks,
            'empty_label' => $emptyLabel,
        ];
    }

    private function buildOverallStatus(
        Order $order,
        array $customerSummary,
        array $supplierDebts,
        array $subcontractorDebts,
    ): array {
        $warnings = [];
        $nextActions = [];
        $counterpartyDebtTotal = (float) $supplierDebts['total_debt'] + (float) $subcontractorDebts['total_debt'];
        $hasUntrackedCounterparty = (bool) $supplierDebts['has_untracked_payments'] || (bool) $subcontractorDebts['has_untracked_payments'];
        $customerRemaining = (float) $customerSummary['remaining_amount'];
        $customerCollected = (float) $customerSummary['collected_amount'];

        if ($customerSummary['has_missing_debit']) {
            $warnings[] = 'Sipariş müşteri borcu hareketi bulunamadı.';
            $nextActions[] = 'Cari borç hareketini kontrol edin.';
        }

        if ($supplierDebts['has_untracked_payments']) {
            $warnings[] = 'Tedarikçi ödemeleri sipariş bazında güvenilir şekilde bağlanamadı.';
            $nextActions[] = 'Tedarikçi cari ekstresini kontrol edin.';
        }

        if ($subcontractorDebts['has_untracked_payments']) {
            $warnings[] = 'Fason ödemeleri sipariş bazında güvenilir şekilde bağlanamadı.';
            $nextActions[] = 'Fason cari ekstresini kontrol edin.';
        }

        if ($order->status === 'cancelled') {
            return [
                'status' => 'cancelled',
                'status_label' => 'Sipariş iptal edildi',
                'status_tone' => 'gray',
                'warnings' => $warnings,
                'next_actions' => $nextActions,
            ];
        }

        if ($customerSummary['has_missing_debit']) {
            return [
                'status' => 'control_required',
                'status_label' => 'Kontrol gerekli',
                'status_tone' => 'red',
                'warnings' => $warnings,
                'next_actions' => $nextActions,
            ];
        }

        if ($customerRemaining > 0.009) {
            return [
                'status' => $customerCollected > 0.009 ? 'partial_collection' : 'open',
                'status_label' => $customerCollected > 0.009 ? 'Kısmi tahsil edildi' : 'Finans açık',
                'status_tone' => $customerCollected > 0.009 ? 'blue' : 'amber',
                'warnings' => $warnings,
                'next_actions' => array_merge(['Tahsilatı tamamlayın.'], $nextActions),
            ];
        }

        if ($counterpartyDebtTotal <= 0.009) {
            return [
                'status' => 'completed',
                'status_label' => 'Finans tamamlandı',
                'status_tone' => 'green',
                'warnings' => $warnings,
                'next_actions' => $nextActions,
            ];
        }

        return [
            'status' => 'counterparty_pending',
            'status_label' => 'Karşı ödeme bekliyor',
            'status_tone' => 'amber',
            'warnings' => $warnings,
            'next_actions' => array_merge(
                ['Karşı borç ödemelerini tamamlayın.'],
                $hasUntrackedCounterparty ? ['Karşı borç hareketlerini cari ekstrelerden doğrulayın.'] : [],
                $nextActions
            ),
        ];
    }

    private function buildMovements(
        Order $order,
        array $customerSummary,
        array $supplierDebts,
        array $subcontractorDebts,
    ): Collection {
        $rows = collect();

        if ($customerSummary['debit_transaction']) {
            /** @var CurrentAccountTransaction $transaction */
            $transaction = $customerSummary['debit_transaction'];
            $rows->push([
                'sort_date' => $transaction->transaction_date,
                'date_label' => optional($transaction->transaction_date)?->format('d.m.Y') ?: '-',
                'movement_label' => 'Müşteri Borcu',
                'source_label' => 'Sipariş Kaynaklı',
                'description' => 'Siparişten oluşan müşteri borcu',
                'debit_label' => $this->money((float) $transaction->amount, $transaction->currency ?: 'TL'),
                'credit_label' => '-',
                'status_label' => $transaction->safeStatusLabel(),
                'status_tone' => $transaction->isCancelled() ? 'gray' : 'blue',
                'action_label' => 'Siparişi Aç',
                'action_url' => route('admin.orders.show', $order),
            ]);
        }

        foreach ($customerSummary['payment_transactions'] as $paymentRow) {
            /** @var OrderPayment $payment */
            $payment = $paymentRow['payment'];
            $rows->push([
                'sort_date' => $payment->paid_at ?: $payment->created_at,
                'date_label' => optional($payment->paid_at ?: $payment->created_at)?->format('d.m.Y') ?: '-',
                'movement_label' => $payment->safePaymentTypeLabel(),
                'source_label' => 'Sipariş Tahsilatı',
                'description' => $payment->payment_note ?: ($payment->payment_reference ?: '-'),
                'debit_label' => $payment->isRefund() ? $this->money((float) abs($payment->amount), $payment->currency ?: 'TL') : '-',
                'credit_label' => !$payment->isRefund() ? $this->money((float) abs($payment->amount), $payment->currency ?: 'TL') : '-',
                'status_label' => $payment->isCancelled() ? 'İptal edildi' : ($payment->paid_at ? 'İşlendi' : 'Bekliyor'),
                'status_tone' => $payment->isCancelled() ? 'gray' : 'green',
                'action_label' => 'Tahsilatı Aç',
                'action_url' => route('admin.finance.show', $order) . '#tahsilat-' . $payment->id,
            ]);
        }

        foreach ($supplierDebts['items'] as $item) {
            $transaction = $item['transaction'];
            $rows->push([
                'sort_date' => $transaction?->transaction_date ?: $item['source_item']->created_at,
                'date_label' => optional($transaction?->transaction_date ?: $item['source_item']->created_at)?->format('d.m.Y') ?: '-',
                'movement_label' => 'Tedarikçi Borcu',
                'source_label' => 'Tedarik Kaynaklı',
                'description' => $item['source_label'],
                'debit_label' => $item['formatted_debt_amount'],
                'credit_label' => '-',
                'status_label' => $item['status_label'],
                'status_tone' => $item['status_tone'],
                'action_label' => $item['action_label'],
                'action_url' => $item['source_url'],
            ]);
        }

        foreach ($subcontractorDebts['items'] as $item) {
            $transaction = $item['transaction'];
            $rows->push([
                'sort_date' => $transaction?->transaction_date ?: $item['source_item']->created_at,
                'date_label' => optional($transaction?->transaction_date ?: $item['source_item']->created_at)?->format('d.m.Y') ?: '-',
                'movement_label' => 'Fason Borcu',
                'source_label' => 'Fason / Üretim Kaynaklı',
                'description' => $item['source_label'],
                'debit_label' => $item['formatted_debt_amount'],
                'credit_label' => '-',
                'status_label' => $item['status_label'],
                'status_tone' => $item['status_tone'],
                'action_label' => $item['action_label'],
                'action_url' => $item['source_url'],
            ]);
        }

        return $rows
            ->sortByDesc(fn (array $row) => optional($row['sort_date'])?->getTimestamp() ?? 0)
            ->values();
    }

    private function customerReceivableStatus(
        Order $order,
        ?CurrentAccountTransaction $debitTransaction,
        float $collectedAmount,
        float $remainingAmount
    ): string {
        if ($order->status === 'cancelled') {
            return 'cancelled';
        }

        if ($debitTransaction === null) {
            return 'missing';
        }

        if ($collectedAmount <= 0.009) {
            return 'collection_pending';
        }

        if ($remainingAmount > 0.009) {
            return 'partial';
        }

        return 'paid';
    }

    private function customerReceivableStatusLabel(
        Order $order,
        ?CurrentAccountTransaction $debitTransaction,
        float $collectedAmount,
        float $remainingAmount
    ): string {
        return match ($this->customerReceivableStatus($order, $debitTransaction, $collectedAmount, $remainingAmount)) {
            'cancelled' => 'Sipariş iptal edildi',
            'missing' => 'Müşteri borcu oluşmadı',
            'collection_pending' => 'Tahsilat bekliyor',
            'partial' => 'Kısmi tahsil edildi',
            'paid' => 'Tahsil edildi',
            default => 'Kontrol gerekli',
        };
    }

    private function customerReceivableStatusTone(
        Order $order,
        ?CurrentAccountTransaction $debitTransaction,
        float $collectedAmount,
        float $remainingAmount
    ): string {
        return match ($this->customerReceivableStatus($order, $debitTransaction, $collectedAmount, $remainingAmount)) {
            'cancelled' => 'gray',
            'missing' => 'red',
            'collection_pending' => 'amber',
            'partial' => 'blue',
            'paid' => 'green',
            default => 'gray',
        };
    }

    private function money(float $amount, ?string $currency = 'TL'): string
    {
        return number_format($amount, 2, ',', '.') . ' ' . ($currency ?: 'TL');
    }
}
