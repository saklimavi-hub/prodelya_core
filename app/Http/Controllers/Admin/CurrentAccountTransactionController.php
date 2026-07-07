<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\OrderItemPrintProduction;
use App\Models\SupplierProcurementRequestItem;
use App\Services\CurrentAccountBalanceSummaryService;
use App\Services\CurrentAccountStatementExportService;
use App\Services\OrderPaymentCurrentAccountSyncService;
use App\Services\OrderCurrentAccountDebitSyncService;
use App\Services\CurrentAccountTransactionService;
use App\Services\SubcontractorProductionCurrentAccountSyncService;
use App\Services\SupplierProcurementCurrentAccountSyncService;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CurrentAccountTransactionController extends Controller
{
    private const VIEW_PERMISSIONS = [
        'view_current_account_transactions',
        'manage_current_account_transactions',
        'cancel_current_account_transactions',
    ];

    public function __construct(
        protected TenantResolver $tenantResolver,
        protected CurrentAccountTransactionService $transactionService,
        protected CurrentAccountBalanceSummaryService $balanceSummaryService,
        protected CurrentAccountStatementExportService $statementExportService,
    ) {
    }

    public function index(Request $request): View
    {
        $tenant = $this->resolveAuthorizedTenant($request, self::VIEW_PERMISSIONS);
        $filters = $this->validateFilters($request);

        $query = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $tenant->id)
            ->with(['currentAccount', 'creator', 'canceller'])
            ->latest('transaction_date')
            ->latest('id');

        $this->applyFilters($query, $filters);

        $transactions = $query->paginate(25)->withQueryString();

        return view('admin.current-account-transactions.index', [
            'transactions' => $transactions,
            'filters' => $filters,
            'summaryCards' => $this->buildSummaryCards(
                CurrentAccountTransaction::query()
                    ->where('tenant_account_id', $tenant->id)
                    ->get()
            ),
            'currentAccounts' => CurrentAccount::query()
                ->where('tenant_account_id', $tenant->id)
                ->orderBy('display_name')
                ->get(['id', 'display_name']),
            'canCancelTransactions' => $request->user()?->hasPermissionInTenant('cancel_current_account_transactions', $tenant->id) ?? false,
            'sourcePayments' => $this->resolveSourcePayments($transactions->getCollection(), $tenant->id),
            'sourceOrders' => $this->resolveSourceOrders($transactions->getCollection(), $tenant->id),
            'sourceProcurementItems' => $this->resolveSourceProcurementItems($transactions->getCollection(), $tenant->id),
            'sourceProductions' => $this->resolveSourceProductions($transactions->getCollection(), $tenant->id),
        ]);
    }

    public function accountTransactions(Request $request, CurrentAccount $currentAccount): View
    {
        $tenant = $this->resolveAuthorizedTenant($request, self::VIEW_PERMISSIONS);
        $this->transactionService->assertTenantScope($currentAccount, $tenant->id);
        $filters = $this->validateAccountFilters($request);

        $currentAccount->loadMissing(['roles', 'links', 'primaryCompanyLink']);

        $transactions = $this->balanceSummaryService
            ->getStatementQuery($currentAccount, $filters)
            ->paginate(20)
            ->withQueryString();

        return view('admin.current-account-transactions.account', [
            'account' => $currentAccount,
            'transactions' => $transactions,
            'filters' => $filters,
            'summary' => $this->balanceSummaryService->summarizeAccounts($tenant->id, [$currentAccount->id])[$currentAccount->id] ?? null,
            'filteredSummary' => $this->balanceSummaryService->summarizeFilteredTransactions($currentAccount, $filters),
            'agingSummary' => $this->balanceSummaryService->buildAgingSummary($currentAccount),
            'runningBalances' => $this->balanceSummaryService->getRunningBalanceMap($currentAccount),
            'openingBalance' => $this->statementExportService->openingBalance($currentAccount, $filters),
            'canManageTransactions' => $request->user()?->hasPermissionInTenant('manage_current_account_transactions', $tenant->id) ?? false,
            'canCancelTransactions' => $request->user()?->hasPermissionInTenant('cancel_current_account_transactions', $tenant->id) ?? false,
            'canViewPaymentActions' => $request->user()?->hasAnyPermissionInTenant([
                'view_order_finance_summary',
                'view_payment_details',
                'manage_payments',
                'mark_payments_received',
            ], $tenant->id) ?? false,
            'canManagePayments' => $request->user()?->hasPermissionInTenant('manage_payments', $tenant->id) ?? false,
            'transactionTypeOptions' => CurrentAccountTransaction::typeLabels(),
            'manualTransactionTypeOptions' => $this->transactionService->manualTransactionTypeOptions($currentAccount),
            'manualQuickActionDefaults' => $this->transactionService->manualQuickActionDefaults($currentAccount),
            'manualFormDefaults' => $this->resolveManualFormDefaults($request, $currentAccount),
            'manualStatusOptions' => CurrentAccountTransaction::manualStatusLabels(),
            'paymentMethodOptions' => CurrentAccountTransaction::paymentMethodLabels(),
            'orderOptions' => $this->resolveOrderOptions($tenant->id),
            'statusOptions' => [
                'all' => 'Tümü',
                'open' => 'Açık',
                'closed' => 'Kapalı / İşlendi',
                'overdue' => 'Vadesi Geçen',
            ],
            'sourcePayments' => $this->resolveSourcePayments($transactions->getCollection(), $tenant->id),
            'sourceOrders' => $this->resolveSourceOrders($transactions->getCollection(), $tenant->id),
            'sourceProcurementItems' => $this->resolveSourceProcurementItems($transactions->getCollection(), $tenant->id),
            'sourceProductions' => $this->resolveSourceProductions($transactions->getCollection(), $tenant->id),
        ]);
    }

    public function store(Request $request, CurrentAccount $currentAccount): RedirectResponse
    {
        $tenant = $this->resolveAuthorizedTenant($request, ['manage_current_account_transactions']);
        $this->transactionService->assertTenantScope($currentAccount, $tenant->id);

        $validated = $request->validate([
            'transaction_type' => ['required', Rule::in(array_keys(CurrentAccountTransaction::manualEntryTypeLabels()))],
            'direction' => ['nullable', Rule::in(array_keys(CurrentAccountTransaction::directionLabels()))],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', Rule::in(['TL', 'TRY', 'USD', 'EUR'])],
            'transaction_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(array_keys(CurrentAccountTransaction::manualStatusLabels()))],
            'document_number' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', Rule::in(array_keys(CurrentAccountTransaction::paymentMethodLabels()))],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
            'redirect_to' => ['nullable', 'string', 'max:2000'],
            'submit_action' => ['nullable', Rule::in(['save', 'save_and_new'])],
        ]);

        $this->transactionService->createManualTransaction($currentAccount, $validated, $request->user());

        $redirectTarget = $this->resolvePostSubmitRedirect(
            $request,
            $currentAccount,
            ($validated['submit_action'] ?? 'save') === 'save_and_new',
            (string) $validated['transaction_type']
        );

        return redirect()
            ->to($redirectTarget)
            ->with('success', 'Cari hareket kaydedildi.');
    }

    public function exportPdf(Request $request, CurrentAccount $currentAccount): Response
    {
        $tenant = $this->resolveAuthorizedTenant($request, self::VIEW_PERMISSIONS);
        $this->transactionService->assertTenantScope($currentAccount, $tenant->id);
        $filters = $this->validateExportFilters($request);

        return $this->statementExportService->pdfResponse($currentAccount->loadMissing('roles'), $filters, $filters['mode']);
    }

    public function exportExcel(Request $request, CurrentAccount $currentAccount): Response
    {
        $tenant = $this->resolveAuthorizedTenant($request, self::VIEW_PERMISSIONS);
        $this->transactionService->assertTenantScope($currentAccount, $tenant->id);
        $filters = $this->validateExportFilters($request);

        return $this->statementExportService->excelResponse($currentAccount->loadMissing('roles'), $filters, $filters['mode']);
    }

    public function cancel(Request $request, CurrentAccountTransaction $transaction): RedirectResponse
    {
        $tenant = $this->resolveAuthorizedTenant($request, ['cancel_current_account_transactions']);
        $this->transactionService->assertTransactionTenantScope($transaction, $tenant->id);
        abort_unless(
            $transaction->isManuallyCancellableFromStatement(),
            403,
            'Bu cari hareketi kendi kaynağından yönetilmelidir.'
        );

        $validated = $request->validate([
            'cancellation_reason' => ['required', 'string'],
        ]);

        $this->transactionService->cancelTransaction($transaction, $validated['cancellation_reason'], $request->user());

        $currentAccount = CurrentAccount::query()
            ->where('tenant_account_id', $tenant->id)
            ->findOrFail($transaction->current_account_id);

        return redirect()
            ->route('admin.current-accounts.transactions.index', $currentAccount)
            ->with('success', 'Cari hareket iptal edildi.');
    }

    private function resolveAuthorizedTenant(Request $request, array $permissions)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $user = $request->user();

        abort_unless(
            $tenant && $user && $user->hasAnyPermissionInTenant($permissions, $tenant->id),
            403
        );

        return $tenant;
    }

    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'current_account_id' => ['nullable', 'integer'],
            'transaction_type' => ['nullable', Rule::in(array_keys(CurrentAccountTransaction::typeLabels()))],
            'direction' => ['nullable', Rule::in(array_keys(CurrentAccountTransaction::directionLabels()))],
            'status' => ['nullable', Rule::in(array_keys(CurrentAccountTransaction::statusLabels()))],
            'currency' => ['nullable', Rule::in(['TRY', 'USD', 'EUR'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);
    }

    private function validateAccountFilters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'transaction_type' => ['nullable', Rule::in(array_keys(CurrentAccountTransaction::typeLabels()))],
            'status' => ['nullable', Rule::in(['all', 'open', 'closed', 'overdue'])],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'type' => $validated['transaction_type'] ?? null,
            'status' => $validated['status'] ?? null,
            'search' => $validated['search'] ?? null,
        ];
    }

    private function validateExportFilters(Request $request): array
    {
        $filters = $this->validateAccountFilters($request);
        $validated = $request->validate([
            'mode' => ['nullable', Rule::in(['summary', 'detailed'])],
        ]);

        return array_merge($filters, [
            'mode' => $validated['mode'] ?? 'summary',
        ]);
    }

    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['current_account_id'])) {
            $query->where('current_account_id', (int) $filters['current_account_id']);
        }

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
    }

    private function buildSummaryCards(Collection $transactions): array
    {
        $open = $transactions->where('status', CurrentAccountTransaction::STATUS_OPEN);
        $cancelled = $transactions->where('status', CurrentAccountTransaction::STATUS_CANCELLED);
        $active = $transactions->where('status', '!=', CurrentAccountTransaction::STATUS_CANCELLED);

        return [
            [
                'label' => 'Açık Hareket',
                'count' => $open->count(),
            ],
            [
                'label' => 'Borç',
                'count' => $active->where('direction', CurrentAccountTransaction::DIRECTION_DEBIT)->count(),
            ],
            [
                'label' => 'Alacak / Ödeme',
                'count' => $active->where('direction', CurrentAccountTransaction::DIRECTION_CREDIT)->count(),
            ],
            [
                'label' => 'İptal',
                'count' => $cancelled->count(),
            ],
        ];
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
            ->get(['id', 'document_number', 'customer_company_id', 'status'])
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
            ->with(['order', 'orderItem', 'orderItemPrint'])
            ->get()
            ->keyBy('id');
    }

    private function resolveManualFormDefaults(Request $request, CurrentAccount $currentAccount): array
    {
        return $this->transactionService->manualFormDefaults(
            $currentAccount,
            $request->query('form_type'),
            [
                'transaction_type' => old('transaction_type'),
                'direction' => old('direction'),
                'status' => old('status'),
                'currency' => old('currency'),
                'transaction_date' => old('transaction_date'),
                'due_date' => old('due_date'),
                'document_number' => old('document_number'),
                'payment_method' => old('payment_method'),
                'order_id' => old('order_id'),
                'description' => old('description'),
                'internal_note' => old('internal_note'),
            ]
        );
    }

    private function resolveOrderOptions(int $tenantId): Collection
    {
        return Order::query()
            ->where('tenant_account_id', $tenantId)
            ->latest('id')
            ->limit(50)
            ->get(['id', 'document_number', 'customer_company_id', 'status']);
    }

    private function resolvePostSubmitRedirect(
        Request $request,
        CurrentAccount $currentAccount,
        bool $reopenQuickPanel,
        string $transactionType
    ): string {
        $fallback = route('admin.current-accounts.transactions.index', $currentAccount);
        $redirectTo = trim((string) $request->input('redirect_to', ''));

        if ($redirectTo === '') {
            if (! $reopenQuickPanel) {
                return $fallback;
            }

            return $fallback . '?quick_panel=1&form_type=' . urlencode($transactionType) . '#hizli-islem-paneli';
        }

        $parts = parse_url($redirectTo);

        if ($parts === false) {
            return $fallback;
        }

        if (isset($parts['host']) && ! hash_equals((string) $request->getHost(), (string) $parts['host'])) {
            return $fallback;
        }

        $path = (string) ($parts['path'] ?? '');

        if ($path === '' || ! str_starts_with($path, '/admin/')) {
            return $fallback;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);

        if ($reopenQuickPanel) {
            $query['quick_panel'] = '1';
            $query['form_type'] = $transactionType;
        } else {
            unset($query['quick_panel']);
        }

        $target = $path;

        if ($query !== []) {
            $target .= '?' . http_build_query($query);
        }

        $fragment = $reopenQuickPanel
            ? 'hizli-islem-paneli'
            : (($parts['fragment'] ?? '') !== '' ? (string) $parts['fragment'] : '');

        if ($fragment !== '') {
            $target .= '#' . $fragment;
        }

        return $target;
    }
}
