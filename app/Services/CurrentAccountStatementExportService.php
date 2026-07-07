<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderPayment;
use App\Models\SupplierProcurementRequestItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CurrentAccountStatementExportService
{
    public function __construct(
        private readonly CurrentAccountBalanceSummaryService $balanceSummaryService,
        private readonly CurrentAccountLedgerDisplayService $ledgerDisplayService,
    ) {
    }

    public function normalizeFilters(array $filters): array
    {
        return [
            'from' => $filters['from'] ?? $filters['statement_from'] ?? null,
            'to' => $filters['to'] ?? $filters['statement_to'] ?? null,
            'type' => $filters['type'] ?? $filters['transaction_type'] ?? $filters['statement_type'] ?? null,
            'status' => $filters['status'] ?? $filters['statement_status'] ?? 'all',
            'search' => $filters['search'] ?? $filters['statement_search'] ?? null,
            'mode' => $filters['mode'] ?? 'summary',
        ];
    }

    public function pdfResponse(CurrentAccount $account, array $filters, string $mode = 'summary'): Response
    {
        $data = $this->buildExportData($account, $filters, $mode);
        $pdf = Pdf::loadView('admin.current-account-transactions.statement-pdf', $data)
            ->setPaper('a4');

        return $pdf->download($this->pdfFileName($account, $data['filters'], $mode));
    }

    public function excelResponse(CurrentAccount $account, array $filters, string $mode = 'summary'): StreamedResponse
    {
        $data = $this->buildExportData($account, $filters, $mode);

        return response()->streamDownload(function () use ($data, $mode): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            $metaRows = [
                ['Cari Adı', $data['account']->safeDisplayName()],
                ['Cari Kodu', $data['account']->account_code ?: '-'],
                ['Ekstre Tipi', $mode === 'detailed' ? 'Detaylı' : 'Genel'],
                ['Tarih Aralığı', $data['periodLabel']],
                ['Oluşturma Tarihi', now()->format('d.m.Y H:i')],
                ['Toplam Alacak', $data['filteredSummary']['formatted_total_credit'] ?? '0,00 TL'],
                ['Toplam Borç', $data['filteredSummary']['formatted_total_debit'] ?? '0,00 TL'],
                ['Bakiye', $data['filteredSummary']['formatted_balance'] ?? '0,00 TL'],
            ];

            if ($mode === 'detailed') {
                $metaRows[] = ['Cari Rolleri', $data['roleLabel']];
                $metaRows[] = ['Vergi No', $data['company']?->tax_number ?: ($data['account']->tax_number ?: '-')];
                $metaRows[] = ['Telefon / WhatsApp', $data['company']?->mobile ?: $data['company']?->phone ?: ($data['account']->mobile ?: ($data['account']->phone ?: '-'))];
                $metaRows[] = ['E-posta', $data['company']?->email ?: ($data['account']->email ?: '-')];
            }

            foreach ($metaRows as $row) {
                fputcsv($handle, $row, ';');
            }

            fputcsv($handle, [], ';');

            if ($data['openingBalance'] !== null) {
                fputcsv($handle, [
                    'Dönem Öncesi Bakiye',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    $data['openingBalance']['label'],
                ], ';');
            }

            $headers = [
                'Tarih',
                'Hareket',
                'Açıklama',
                'Vade Tarihi',
                'Borç',
                'Alacak',
                'Bakiye',
            ];

            if ($mode === 'detailed') {
                $headers = array_merge($headers, [
                    'Kaynak',
                    'Durum',
                    'Belge No',
                    'Sipariş No',
                    'Ödeme Yöntemi',
                    'Vadesi Geçmiş Gün',
                    'Rol',
                ]);
            }

            fputcsv($handle, $headers, ';');

            foreach ($data['rows'] as $row) {
                $csvRow = [
                    $row['transaction_date'],
                    $row['type_label'],
                    $row['description'],
                    $row['due_date'],
                    $row['debit_amount'],
                    $row['credit_amount'],
                    $row['balance_label'],
                ];

                if ($mode === 'detailed') {
                    $csvRow = array_merge($csvRow, [
                        $row['source_label'],
                        $row['status_label'],
                        $row['document_number'],
                        $row['order_number'],
                        $row['payment_method'],
                        $row['overdue_days'],
                        $data['roleLabel'],
                    ]);
                }

                fputcsv($handle, $csvRow, ';');
            }

            if ($mode === 'detailed' && ! empty($data['agingSummary']['buckets'])) {
                fputcsv($handle, [], ';');
                fputcsv($handle, ['Vade Yaşlandırma'], ';');
                fputcsv($handle, ['Grup', 'Tutar', 'Hareket Sayısı'], ';');

                foreach ($data['agingSummary']['buckets'] as $bucket) {
                    fputcsv($handle, [
                        $bucket['label'],
                        $bucket['formatted_amount'],
                        $bucket['count'],
                    ], ';');
                }
            }

            fclose($handle);
        }, $this->excelFileName($account, $data['filters'], $mode), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function renderPdfHtml(CurrentAccount $account, array $filters, string $mode = 'summary'): string
    {
        return view('admin.current-account-transactions.statement-pdf', $this->buildExportData($account, $filters, $mode))->render();
    }

    public function buildExportData(CurrentAccount $account, array $filters, string $mode = 'summary'): array
    {
        $filters = $this->normalizeFilters(array_merge($filters, ['mode' => $mode]));
        $mode = $filters['mode'] === 'detailed' ? 'detailed' : 'summary';

        $account->loadMissing(['tenant', 'roles', 'primaryCompanyLink']);
        $company = $this->resolveLinkedCompany($account);
        $statementQuery = $this->baseStatementQuery($account, $filters);
        $transactions = (clone $statementQuery)
            ->reorder()
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $sourcePayments = $this->resolveSourcePayments($transactions, (int) $account->tenant_account_id);
        $sourceOrders = $this->resolveSourceOrders($transactions, (int) $account->tenant_account_id);
        $sourceProcurementItems = $this->resolveSourceProcurementItems($transactions, (int) $account->tenant_account_id);
        $sourceProductions = $this->resolveSourceProductions($transactions, (int) $account->tenant_account_id);
        $overallSummary = $this->balanceSummaryService
            ->summarizeAccounts((int) $account->tenant_account_id, [$account->id])[$account->id] ?? null;
        $filteredSummary = $this->balanceSummaryService->summarizeFilteredTransactions($account, $filters);
        $agingSummary = $this->balanceSummaryService->buildAgingSummary($account);
        $openingBalance = $this->openingBalance($account, $filters);
        $periodLabel = $this->buildPeriodLabel($filters);
        $roleLabel = $account->roles->map(fn ($role) => $role->safeRoleLabel())->implode(', ') ?: 'Tanımsız';
        $currency = (string) ($filteredSummary['currency'] ?? $overallSummary['currency'] ?? $account->default_currency ?: 'TL');

        $runningBalance = (float) ($openingBalance['amount'] ?? 0);
        $rows = [];

        foreach ($transactions as $transaction) {
            $runningBalance += $this->balanceSummaryService->signedAmountForTransaction($account, $transaction);

            $sourcePayment = $sourcePayments->get($transaction->source_id);
            $sourceOrder = $sourceOrders->get($transaction->source_id);
            $sourceProcurementItem = $sourceProcurementItems->get($transaction->source_id);
            $sourceProduction = $sourceProductions->get($transaction->source_id);
            $documentNumber = $transaction->safeManualDocumentNumber() ?: '-';
            $orderNumber = $transaction->safeManualOrderNumber() ?: '-';

            if ($transaction->source_type === OrderPaymentCurrentAccountSyncService::SOURCE_TYPE && $sourcePayment) {
                $documentNumber = $sourcePayment->payment_reference ?: ($sourcePayment->order?->document_number ?: '-');
                $orderNumber = $sourcePayment->order?->document_number ?: '-';
            } elseif ($transaction->source_type === OrderCurrentAccountDebitSyncService::SOURCE_TYPE && $sourceOrder) {
                $documentNumber = $sourceOrder->document_number ?: '-';
                $orderNumber = $sourceOrder->document_number ?: '-';
            } elseif ($transaction->source_type === SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE && $sourceProcurementItem) {
                $documentNumber = $sourceProcurementItem->request?->request_number ?: '-';
                $orderNumber = $sourceProcurementItem->order?->document_number ?: '-';
            } elseif ($transaction->source_type === SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE && $sourceProduction) {
                $documentNumber = $sourceProduction->order?->document_number ?: '-';
                $orderNumber = $sourceProduction->order?->document_number ?: '-';
            }

            $description = trim((string) ($transaction->description ?: '-'));
            $description = Str::limit($description, $mode === 'detailed' ? 160 : 90, '...');
            $paymentMethod = $transaction->safeManualPaymentMethodLabel();
            if (! $paymentMethod && $sourcePayment) {
                $paymentMethod = $sourcePayment->safePaymentMethodLabel();
            }

            $dueDate = $transaction->due_date?->format('d.m.Y') ?: '-';
            $overdueDays = '';

            if ($transaction->due_date && in_array((string) $transaction->status, [
                CurrentAccountTransaction::STATUS_OPEN,
                CurrentAccountTransaction::STATUS_PARTIALLY_PAID,
            ], true) && $transaction->due_date->isPast()) {
                $overdueDays = (string) $transaction->due_date->diffInDays(now()->startOfDay());
            }

            $rows[] = [
                'transaction_date' => $transaction->transaction_date?->format('d.m.Y') ?: '-',
                'type_label' => $transaction->safeTypeLabel(),
                'status_label' => $transaction->safeStatusLabel(),
                'document_number' => $documentNumber,
                'order_number' => $orderNumber,
                'description' => $description,
                'due_date' => $dueDate,
                'payment_method' => $paymentMethod ?: '-',
                'debit_amount' => $transaction->isDebit() ? MoneyFormatter::formatAmount((float) $transaction->amount) : '',
                'credit_amount' => $transaction->isCredit() ? MoneyFormatter::formatAmount((float) $transaction->amount) : '',
                'balance_label' => $this->ledgerDisplayService->formatSignedBalance($runningBalance, $transaction->currency ?: $currency),
                'balance_direction' => $runningBalance > 0 ? 'receivable' : ($runningBalance < 0 ? 'payable' : 'closed'),
                'balance_direction_label' => $this->ledgerDisplayService->balanceStatusLabel($runningBalance),
                'currency' => (string) ($transaction->currency ?: $currency),
                'source_label' => $this->sourceLabel($transaction),
                'reference_label' => $this->referenceLabel($transaction, $sourcePayment, $sourceOrder, $sourceProcurementItem, $sourceProduction),
                'overdue_days' => $overdueDays,
            ];
        }

        return [
            'account' => $account,
            'company' => $company,
            'tenantName' => $account->tenant?->name ?: 'Prodelya',
            'overallSummary' => $overallSummary,
            'filteredSummary' => $filteredSummary,
            'agingSummary' => $agingSummary,
            'openingBalance' => $openingBalance,
            'filters' => $filters,
            'periodLabel' => $periodLabel,
            'rows' => $rows,
            'mode' => $mode,
            'currency' => $currency,
            'roleLabel' => $roleLabel,
            'generatedAt' => now()->format('d.m.Y H:i'),
            'filterLabel' => $this->buildFilterLabel($filters),
            'addressLabel' => $this->buildAddressLabel($company),
        ];
    }

    private function baseStatementQuery(CurrentAccount $account, array $filters): Builder
    {
        return $this->balanceSummaryService
            ->getStatementQuery($account, $filters)
            ->where(function (Builder $query): void {
                $query
                    ->where('status', '!=', CurrentAccountTransaction::STATUS_CANCELLED)
                    ->orWhereNull('status');
            });
    }

    public function openingBalance(CurrentAccount $account, array $filters): ?array
    {
        if (empty($filters['from'])) {
            return null;
        }

        $transactions = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $account->tenant_account_id)
            ->where('current_account_id', $account->id)
            ->whereDate('transaction_date', '<', (string) $filters['from'])
            ->where(function (Builder $query): void {
                $query
                    ->where('status', '!=', CurrentAccountTransaction::STATUS_CANCELLED)
                    ->orWhereNull('status');
            })
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $amount = 0.0;

        foreach ($transactions as $transaction) {
            $amount += $this->balanceSummaryService->signedAmountForTransaction($account, $transaction);
        }

        return [
            'amount' => round($amount, 2),
            'label' => $this->ledgerDisplayService->formatSignedBalance(round($amount, 2), $account->default_currency ?: 'TL'),
            'direction_label' => $this->ledgerDisplayService->balanceStatusLabel(round($amount, 2)),
        ];
    }

    private function resolveLinkedCompany(CurrentAccount $account): ?Company
    {
        $companyId = $account->primaryCompanyLink?->link_id;

        if (! $companyId) {
            return null;
        }

        return Company::query()
            ->where('tenant_account_id', $account->tenant_account_id)
            ->with(['addresses' => fn ($query) => $query->orderByDesc('is_default')->orderBy('id')])
            ->find($companyId);
    }

    private function resolveSourcePayments(Collection $transactions, int $tenantId): Collection
    {
        $paymentIds = $transactions
            ->where('source_type', OrderPaymentCurrentAccountSyncService::SOURCE_TYPE)
            ->pluck('source_id')
            ->filter()
            ->unique()
            ->values();

        if ($paymentIds->isEmpty()) {
            return collect();
        }

        return OrderPayment::query()
            ->where('tenant_account_id', $tenantId)
            ->whereIn('id', $paymentIds->all())
            ->with('order')
            ->get()
            ->keyBy('id');
    }

    private function resolveSourceOrders(Collection $transactions, int $tenantId): Collection
    {
        $orderIds = $transactions
            ->where('source_type', OrderCurrentAccountDebitSyncService::SOURCE_TYPE)
            ->pluck('source_id')
            ->filter()
            ->unique()
            ->values();

        if ($orderIds->isEmpty()) {
            return collect();
        }

        return Order::query()
            ->where('tenant_account_id', $tenantId)
            ->whereIn('id', $orderIds->all())
            ->get(['id', 'document_number'])
            ->keyBy('id');
    }

    private function resolveSourceProcurementItems(Collection $transactions, int $tenantId): Collection
    {
        $itemIds = $transactions
            ->where('source_type', SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE)
            ->pluck('source_id')
            ->filter()
            ->unique()
            ->values();

        if ($itemIds->isEmpty()) {
            return collect();
        }

        return SupplierProcurementRequestItem::query()
            ->where('tenant_account_id', $tenantId)
            ->whereIn('id', $itemIds->all())
            ->with(['request', 'order'])
            ->get()
            ->keyBy('id');
    }

    private function resolveSourceProductions(Collection $transactions, int $tenantId): Collection
    {
        $productionIds = $transactions
            ->where('source_type', SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE)
            ->pluck('source_id')
            ->filter()
            ->unique()
            ->values();

        if ($productionIds->isEmpty()) {
            return collect();
        }

        return OrderItemPrintProduction::query()
            ->where('tenant_account_id', $tenantId)
            ->whereIn('id', $productionIds->all())
            ->with(['order', 'orderItemPrint'])
            ->get()
            ->keyBy('id');
    }

    private function buildPeriodLabel(array $filters): string
    {
        $from = $filters['from'] ? date('d.m.Y', strtotime((string) $filters['from'])) : null;
        $to = $filters['to'] ? date('d.m.Y', strtotime((string) $filters['to'])) : null;

        if ($from && $to) {
            return $this->formatPeriodDate($from) . ' — ' . $this->formatPeriodDate($to);
        }

        if ($from) {
            return $this->formatPeriodDate($from) . ' sonrası';
        }

        if ($to) {
            return $this->formatPeriodDate($to) . ' öncesi';
        }

        return 'Tüm hareketler';
    }

    private function buildFilterLabel(array $filters): string
    {
        $parts = [];

        if (! empty($filters['type'])) {
            $parts[] = CurrentAccountTransaction::typeLabels()[$filters['type']] ?? 'Diğer Hareket';
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $parts[] = match ($filters['status']) {
                'open' => 'Açık',
                'closed' => 'Kapalı / İşlendi',
                'overdue' => 'Vadesi Geçen',
                default => 'Tümü',
            };
        }

        if (! empty($filters['search'])) {
            $parts[] = 'Arama: ' . Str::limit(trim((string) $filters['search']), 60, '...');
        }

        return $parts === [] ? 'Ek filtre yok' : implode(' • ', $parts);
    }

    private function buildAddressLabel(?Company $company): ?string
    {
        $address = $company?->addresses?->first();

        if (! $address) {
            return null;
        }

        return collect([
            $address->address,
            $address->district,
            $address->city,
        ])->filter()->implode(', ');
    }

    private function sourceLabel(CurrentAccountTransaction $transaction): string
    {
        return match ($transaction->source_type) {
            CurrentAccountTransaction::SOURCE_TYPE_MANUAL => 'Manuel',
            OrderCurrentAccountDebitSyncService::SOURCE_TYPE => 'Sipariş',
            OrderPaymentCurrentAccountSyncService::SOURCE_TYPE => 'Sipariş Tahsilatı',
            SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE => 'Tedarik',
            SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE => 'Fason Üretim',
            default => 'Diğer',
        };
    }

    private function referenceLabel(
        CurrentAccountTransaction $transaction,
        ?OrderPayment $sourcePayment,
        ?Order $sourceOrder,
        ?SupplierProcurementRequestItem $sourceProcurementItem,
        ?OrderItemPrintProduction $sourceProduction,
    ): string {
        return match ($transaction->source_type) {
            OrderCurrentAccountDebitSyncService::SOURCE_TYPE => $sourceOrder?->document_number ?: '-',
            OrderPaymentCurrentAccountSyncService::SOURCE_TYPE => $sourcePayment?->payment_reference ?: ($sourcePayment?->order?->document_number ?: '-'),
            SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE => $sourceProcurementItem?->request?->request_number ?: '-',
            SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE => $sourceProduction?->order?->document_number ?: '-',
            default => $transaction->safeManualDocumentNumber() ?: '-',
        };
    }

    private function pdfFileName(CurrentAccount $account, array $filters, string $mode): string
    {
        $name = $this->sanitizeSegment($account->safeDisplayName());
        $period = $filters['from'] || $filters['to']
            ? trim(($filters['from'] ?: 'baslangic') . '-' . ($filters['to'] ?: 'bugun'), '-')
            : 'tum-hareketler';

        return sprintf('%s-cari-ekstre-%s-%s.pdf', $name, $mode === 'detailed' ? 'detayli' : 'genel', $this->sanitizeSegment($period));
    }

    private function excelFileName(CurrentAccount $account, array $filters, string $mode): string
    {
        $name = $this->sanitizeSegment($account->safeDisplayName());
        $period = $filters['from'] || $filters['to']
            ? trim(($filters['from'] ?: 'baslangic') . '-' . ($filters['to'] ?: 'bugun'), '-')
            : 'tum-hareketler';

        return sprintf('%s-cari-ekstre-%s-%s.csv', $name, $mode === 'detailed' ? 'detayli' : 'genel', $this->sanitizeSegment($period));
    }

    private function sanitizeSegment(string $value): string
    {
        $ascii = Str::ascii($value);
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '-', $ascii ?? '') ?: 'dosya';

        return trim($ascii, '-');
    }

    private function formatPeriodDate(string $date): string
    {
        return Carbon::parse($date)->locale('tr')->translatedFormat('d F Y');
    }
}
