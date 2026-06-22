<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountTransaction;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderPayment;
use App\Models\SupplierProcurementRequestItem;
use App\Services\OrderPaymentCurrentAccountSyncService;
use App\Services\CurrentAccountTransactionService;
use App\Services\SubcontractorProductionCurrentAccountSyncService;
use App\Services\SupplierProcurementCurrentAccountSyncService;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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
            'sourceProcurementItems' => $this->resolveSourceProcurementItems($transactions->getCollection(), $tenant->id),
            'sourceProductions' => $this->resolveSourceProductions($transactions->getCollection(), $tenant->id),
        ]);
    }

    public function accountTransactions(Request $request, CurrentAccount $currentAccount): View
    {
        $tenant = $this->resolveAuthorizedTenant($request, self::VIEW_PERMISSIONS);
        $this->transactionService->assertTenantScope($currentAccount, $tenant->id);
        $filters = $this->validateFilters($request);

        $transactions = $this->transactionService
            ->getAccountTransactions($currentAccount, $filters)
            ->paginate(20)
            ->withQueryString();

        return view('admin.current-account-transactions.account', [
            'account' => $currentAccount->loadMissing(['roles', 'links']),
            'transactions' => $transactions,
            'filters' => $filters,
            'summary' => $this->transactionService->getAccountSummary($currentAccount),
            'canManageTransactions' => $request->user()?->hasPermissionInTenant('manage_current_account_transactions', $tenant->id) ?? false,
            'canCancelTransactions' => $request->user()?->hasPermissionInTenant('cancel_current_account_transactions', $tenant->id) ?? false,
            'transactionTypeOptions' => CurrentAccountTransaction::typeLabels(),
            'directionOptions' => CurrentAccountTransaction::directionLabels(),
            'statusOptions' => CurrentAccountTransaction::statusLabels(),
            'sourcePayments' => $this->resolveSourcePayments($transactions->getCollection(), $tenant->id),
            'sourceProcurementItems' => $this->resolveSourceProcurementItems($transactions->getCollection(), $tenant->id),
            'sourceProductions' => $this->resolveSourceProductions($transactions->getCollection(), $tenant->id),
        ]);
    }

    public function store(Request $request, CurrentAccount $currentAccount): RedirectResponse
    {
        $tenant = $this->resolveAuthorizedTenant($request, ['manage_current_account_transactions']);
        $this->transactionService->assertTenantScope($currentAccount, $tenant->id);

        $validated = $request->validate([
            'transaction_type' => ['required', Rule::in(array_keys(CurrentAccountTransaction::typeLabels()))],
            'direction' => ['required', Rule::in(array_keys(CurrentAccountTransaction::directionLabels()))],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', Rule::in(['TRY', 'USD', 'EUR'])],
            'transaction_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
        ]);

        $this->transactionService->createManualTransaction($currentAccount, $validated, $request->user());

        return redirect()
            ->route('admin.current-accounts.transactions.index', $currentAccount)
            ->with('success', 'Cari hareket başarıyla oluşturuldu.');
    }

    public function cancel(Request $request, CurrentAccountTransaction $transaction): RedirectResponse
    {
        $tenant = $this->resolveAuthorizedTenant($request, ['cancel_current_account_transactions']);
        $this->transactionService->assertTransactionTenantScope($transaction, $tenant->id);

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
}
