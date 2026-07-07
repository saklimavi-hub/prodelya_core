<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\Supplier;
use App\Models\TenantSupplierAccess;
use App\Services\CompanyDuplicateResolutionService;
use App\Services\CurrentAccountBalanceSummaryService;
use App\Services\CurrentAccountSyncService;
use App\Services\CurrentAccountTransactionService;
use App\Services\TenantResolver;
use App\Services\UsageLimitGuardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CurrentAccountController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected CurrentAccountSyncService $syncService,
        protected CurrentAccountBalanceSummaryService $balanceSummaryService,
        protected CurrentAccountTransactionService $transactionService,
        protected UsageLimitGuardService $usageLimitGuardService,
        protected CompanyDuplicateResolutionService $companyDuplicateResolutionService,
    ) {
    }

    public function index(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $canViewFinancialData = $request->user()?->canViewFinancialData($tenant->id) ?? false;
        $tabOptions = $this->listTabs();
        $requestedTab = (string) $request->query('tab', 'aktif');
        $activeTab = array_key_exists($requestedTab, $tabOptions) ? $requestedTab : 'aktif';
        $filters = $this->sanitizeListFilters($request, $activeTab, $canViewFinancialData);

        $query = CurrentAccount::query()
            ->where('tenant_account_id', $tenant->id)
            ->with(['roles', 'primaryCompanyLink']);

        if ($filters['search'] !== '') {
            $search = trim((string) $filters['search']);

            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('display_name', 'like', '%' . $search . '%')
                    ->orWhere('legal_name', 'like', '%' . $search . '%')
                    ->orWhere('short_name', 'like', '%' . $search . '%')
                    ->orWhere('tax_number', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%')
                    ->orWhere('mobile', 'like', '%' . $search . '%');
            });
        }

        if ($filters['role'] !== '') {
            $query->whereHas('roles', function ($builder) use ($filters): void {
                $builder->where('role', (string) $filters['role']);
            });
        }

        $this->applyListTabScope($query, $activeTab, $tenant->id);

        if ($filters['status'] !== '') {
            $query->where('status', (string) $filters['status']);
        }

        if ($filters['risk_status'] !== '') {
            $query->where('risk_status', (string) $filters['risk_status']);
        }

        $this->applyMovementStatusFilter($query, $filters['movement_status'], $tenant->id);
        $this->applyBalanceStatusFilter($query, $filters['balance_status'], $tenant->id, $canViewFinancialData);

        $accounts = $query
            ->orderBy('display_name')
            ->paginate(20)
            ->withQueryString();

        $balanceSummaries = $canViewFinancialData
            ? $this->balanceSummaryService->summarizeAccounts($tenant->id, $accounts->getCollection()->pluck('id')->all())
            : [];

        $linkedCompanyIds = $accounts->getCollection()
            ->pluck('primaryCompanyLink.link_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $linkedCompanies = Company::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('id', $linkedCompanyIds)
            ->get()
            ->keyBy('id');

        $duplicateSummaries = [];
        $archivedLinkedCompanyIds = $accounts->getCollection()
            ->filter(fn (CurrentAccount $account): bool => in_array($account->status, [
                CurrentAccount::STATUS_PASSIVE,
                CurrentAccount::STATUS_BLOCKED,
                CurrentAccount::STATUS_ARCHIVED,
            ], true))
            ->pluck('primaryCompanyLink.link_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($archivedLinkedCompanyIds !== []) {
            $archivedLinkedCompanies = Company::query()
                ->where('tenant_account_id', $tenant->id)
                ->whereIn('id', $archivedLinkedCompanyIds)
                ->with(['companyRoles', 'contacts', 'addresses', 'portalUsers'])
                ->get();

            foreach ($archivedLinkedCompanies as $linkedCompany) {
                $duplicateSummaries[$linkedCompany->id] = $this->companyDuplicateResolutionService->auditCompanyDuplicateStatus($linkedCompany);
            }
        }

        $stats = [
            'total' => CurrentAccount::query()->where('tenant_account_id', $tenant->id)->count(),
            'customer' => $this->countByRole($tenant->id, CurrentAccountRole::ROLE_CUSTOMER),
            'supplier' => $this->countByRole($tenant->id, CurrentAccountRole::ROLE_SUPPLIER),
            'subcontractor' => $this->countByRole($tenant->id, CurrentAccountRole::ROLE_SUBCONTRACTOR),
            'carrier' => $this->countByRole($tenant->id, CurrentAccountRole::ROLE_CARRIER),
            'other' => $this->countByRole($tenant->id, CurrentAccountRole::ROLE_OTHER),
            'inactive' => CurrentAccount::query()
                ->where('tenant_account_id', $tenant->id)
                ->whereIn('status', [CurrentAccount::STATUS_PASSIVE, CurrentAccount::STATUS_BLOCKED, CurrentAccount::STATUS_ARCHIVED])
                ->count(),
        ];

        $tabStats = $this->tabStats($tenant->id);
        $selectedTabSummary = $tabStats[$activeTab] ?? ['count' => 0, 'label' => 'Aktif Bakiyeler'];

        return view('admin.current-accounts.index', [
            'accounts' => $accounts,
            'stats' => $stats,
            'tabStats' => $tabStats,
            'selectedTabSummary' => $selectedTabSummary,
            'balanceSummaries' => $balanceSummaries,
            'linkedCompanies' => $linkedCompanies,
            'duplicateSummaries' => $duplicateSummaries,
            'canViewFinancialData' => $canViewFinancialData,
            'filters' => $filters,
            'statusFilterOptions' => $this->statusFilterOptions($activeTab),
            'movementStatusOptions' => $this->movementStatusOptions(),
            'balanceStatusOptions' => $this->balanceStatusOptions(),
            'showStatusFilter' => in_array($activeTab, ['tumu', 'arsiv'], true),
            'showBalanceFilter' => $canViewFinancialData,
            'filterNotice' => $filters['filter_notice'],
            'listTabs' => $tabOptions,
            'activeTab' => $activeTab,
            'roleTabs' => $this->roleTabs(),
        ]);
    }

    public function create(): RedirectResponse
    {
        return redirect()
            ->route('admin.companies.create')
            ->with('info', 'Yeni cari / firma kaydı tek ekran hızlı form ile açılır.');
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $this->usageLimitGuardService->assertCanCreate($tenant, 'current_accounts');
        $validated = $this->validatePayload($request, null, $tenant->id);
        $roles = collect($validated['roles'] ?? [])->unique()->values()->all();

        $account = DB::transaction(function () use ($tenant, $validated, $roles, $request): CurrentAccount {
            $account = CurrentAccount::query()->create([
                'tenant_account_id' => $tenant->id,
                'account_code' => $validated['account_code'] ?? null,
                'display_name' => $validated['display_name'],
                'legal_name' => $validated['legal_name'] ?? null,
                'short_name' => $validated['short_name'] ?? null,
                'tax_office' => $validated['tax_office'] ?? null,
                'tax_number' => $validated['tax_number'] ?? null,
                'tc_no' => $validated['tc_no'] ?? null,
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'mobile' => $validated['mobile'] ?? null,
                'website' => $validated['website'] ?? null,
                'default_currency' => $validated['default_currency'] ?? null,
                'payment_terms_days' => $validated['payment_terms_days'] ?? null,
                'risk_limit' => $validated['risk_limit'] ?? null,
                'risk_status' => $validated['risk_status'] ?? null,
                'status' => $validated['status'],
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]);

            $this->syncService->syncRoles($account, $roles);
            $this->syncService->ensureCompanyForCurrentAccount($account, $roles);
            $this->syncSupplierLinks($account, $tenant->id, $roles, $validated['supplier_id'] ?? null);

            return $account->fresh(['roles', 'links']);
        });

        return $this->redirectToPreferredAccountScreen($account, 'Cari kart başarıyla oluşturuldu.');
    }

    public function show(Request $request, CurrentAccount $currentAccount): View|RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $this->guardTenantScope($currentAccount, $tenant->id);

        $currentAccount->load(['roles', 'links']);
        if ($linkedCompany = $this->resolveLinkedCompany($currentAccount)) {
            return redirect()->route('admin.companies.show', $linkedCompany);
        }

        $canViewTransactions = $request->user()?->hasAnyPermissionInTenant([
            'view_current_account_transactions',
            'manage_current_account_transactions',
            'cancel_current_account_transactions',
        ], $tenant->id) ?? false;

        return view('admin.current-accounts.show', array_merge(
            $this->showViewData($currentAccount, $canViewTransactions),
            [
                'account' => $currentAccount,
                'canViewTransactions' => $canViewTransactions,
                'canManageTransactions' => $request->user()?->hasPermissionInTenant('manage_current_account_transactions', $tenant->id) ?? false,
                'canCancelTransactions' => $request->user()?->hasPermissionInTenant('cancel_current_account_transactions', $tenant->id) ?? false,
            ]
        ));
    }

    public function edit(Request $request, CurrentAccount $currentAccount): View|RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $this->guardTenantScope($currentAccount, $tenant->id);

        $currentAccount->load(['roles', 'links']);
        if ($linkedCompany = $this->resolveLinkedCompany($currentAccount)) {
            return redirect()->route('admin.companies.edit', $linkedCompany);
        }

        return view('admin.current-accounts.edit', array_merge(
            $this->formViewData(),
            [
                'account' => $currentAccount,
                'selectedRoles' => $currentAccount->roles->pluck('role')->all(),
            ]
        ));
    }

    public function update(Request $request, CurrentAccount $currentAccount): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $this->guardTenantScope($currentAccount, $tenant->id);

        $validated = $this->validatePayload($request, $currentAccount, $tenant->id);
        $roles = collect($validated['roles'] ?? [])->unique()->values()->all();

        DB::transaction(function () use ($currentAccount, $validated, $roles, $request): void {
            $currentAccount->fill([
                'account_code' => $validated['account_code'] ?? null,
                'display_name' => $validated['display_name'],
                'legal_name' => $validated['legal_name'] ?? null,
                'short_name' => $validated['short_name'] ?? null,
                'tax_office' => $validated['tax_office'] ?? null,
                'tax_number' => $validated['tax_number'] ?? null,
                'tc_no' => $validated['tc_no'] ?? null,
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'mobile' => $validated['mobile'] ?? null,
                'website' => $validated['website'] ?? null,
                'default_currency' => $validated['default_currency'] ?? null,
                'payment_terms_days' => $validated['payment_terms_days'] ?? null,
                'risk_limit' => $validated['risk_limit'] ?? null,
                'risk_status' => $validated['risk_status'] ?? null,
                'status' => $validated['status'],
                'notes' => $validated['notes'] ?? null,
                'updated_by' => $request->user()?->id,
            ]);
            $currentAccount->save();

            $this->syncService->syncRoles($currentAccount, $roles);
            $this->syncService->ensureCompanyForCurrentAccount($currentAccount, $roles);
            $this->syncSupplierLinks($currentAccount, $currentAccount->tenant_account_id, $roles, $validated['supplier_id'] ?? null);
        });

        return $this->redirectToPreferredAccountScreen($currentAccount->fresh(), 'Cari kart başarıyla güncellendi.');
    }

    public function updateStatus(Request $request, CurrentAccount $currentAccount): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $this->guardTenantScope($currentAccount, $tenant->id);

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(CurrentAccount::statusLabels()))],
        ]);

        $currentAccount->forceFill([
            'status' => $validated['status'],
            'updated_by' => $request->user()?->id,
        ])->save();

        $linkedCompanyId = CurrentAccountLink::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('current_account_id', $currentAccount->id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->value('link_id');

        if ($linkedCompanyId) {
            $company = Company::query()
                ->where('tenant_account_id', $tenant->id)
                ->find($linkedCompanyId);

            if ($company) {
                $company->forceFill([
                    'status' => $this->normalizeCurrentAccountStatusForCompany($validated['status']),
                ])->save();
            }
        }

        return $this->redirectToPreferredAccountScreen($currentAccount->fresh(), 'Cari durumu güncellendi.');
    }

    public function attachSupplier(Request $request, CurrentAccount $currentAccount): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $this->guardTenantScope($currentAccount, $tenant->id);

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
        ]);

        if (!$currentAccount->hasRole(CurrentAccountRole::ROLE_SUPPLIER)) {
            return back()->withErrors([
                'supplier_id' => 'Global supplier bağlantısı yalnız tedarikçi rolü olan cariler için kullanılabilir.',
            ]);
        }

        $this->guardUniqueSupplierLink($tenant->id, (int) $validated['supplier_id'], $currentAccount->id);

        $supplier = Supplier::query()->findOrFail($validated['supplier_id']);

        DB::transaction(function () use ($tenant, $currentAccount, $supplier): void {
            CurrentAccountLink::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('current_account_id', $currentAccount->id)
                ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
                ->delete();

            CurrentAccountLink::query()->create([
                'tenant_account_id' => $tenant->id,
                'current_account_id' => $currentAccount->id,
                'link_type' => CurrentAccountLink::LINK_SUPPLIER,
                'link_id' => $supplier->id,
                'is_primary' => true,
                'meta_json' => [
                    'linked_via' => 'tenant_current_account_ui',
                ],
            ]);

            $tenantSupplierAccess = TenantSupplierAccess::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('supplier_id', $supplier->id)
                ->first();

            if ($tenantSupplierAccess) {
                CurrentAccountLink::query()->updateOrCreate(
                    [
                        'tenant_account_id' => $tenant->id,
                        'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
                        'link_id' => $tenantSupplierAccess->id,
                    ],
                    [
                        'current_account_id' => $currentAccount->id,
                        'is_primary' => true,
                        'meta_json' => [
                            'supplier_id' => $supplier->id,
                            'linked_via' => 'tenant_current_account_ui',
                        ],
                    ]
                );
            }
        });

        return $this->redirectToPreferredAccountScreen($currentAccount->fresh(['roles', 'links']), 'Global supplier bağlantısı kaydedildi.');
    }

    public function detachSupplier(Request $request, CurrentAccount $currentAccount): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $this->guardTenantScope($currentAccount, $tenant->id);

        DB::transaction(function () use ($tenant, $currentAccount): void {
            $supplierLinkIds = CurrentAccountLink::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('current_account_id', $currentAccount->id)
                ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
                ->pluck('link_id');

            CurrentAccountLink::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('current_account_id', $currentAccount->id)
                ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
                ->delete();

            if ($supplierLinkIds->isNotEmpty()) {
                $tenantSupplierAccessIds = TenantSupplierAccess::query()
                    ->where('tenant_account_id', $tenant->id)
                    ->whereIn('supplier_id', $supplierLinkIds->all())
                    ->pluck('id');

                if ($tenantSupplierAccessIds->isNotEmpty()) {
                    CurrentAccountLink::query()
                        ->where('tenant_account_id', $tenant->id)
                        ->where('current_account_id', $currentAccount->id)
                        ->where('link_type', CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS)
                        ->whereIn('link_id', $tenantSupplierAccessIds->all())
                        ->delete();
                }
            }
        });

        return $this->redirectToPreferredAccountScreen($currentAccount->fresh(['roles', 'links']), 'Global supplier bağlantısı kaldırıldı.');
    }

    private function validatePayload(Request $request, ?CurrentAccount $account, int $tenantId): array
    {
        return $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:255'],
            'account_code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('current_accounts', 'account_code')
                    ->where(fn ($query) => $query->where('tenant_account_id', $tenantId))
                    ->ignore($account?->id),
            ],
            'status' => ['required', Rule::in([
                CurrentAccount::STATUS_ACTIVE,
                CurrentAccount::STATUS_PASSIVE,
                CurrentAccount::STATUS_BLOCKED,
                CurrentAccount::STATUS_ARCHIVED,
            ])],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['required', Rule::in(array_keys(CurrentAccountRole::roleLabels()))],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url', 'max:255'],
            'tax_office' => ['nullable', 'string', 'max:100'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'tc_no' => ['nullable', 'string', 'max:20'],
            'default_currency' => ['nullable', Rule::in(['TRY', 'USD', 'EUR'])],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'risk_limit' => ['nullable', 'numeric', 'min:0'],
            'risk_status' => ['nullable', Rule::in(array_keys(CurrentAccount::riskStatusLabels()))],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'notes' => ['nullable', 'string'],
        ]);
    }

    private function guardTenantScope(CurrentAccount $account, int $tenantId): void
    {
        if ($account->tenant_account_id !== $tenantId) {
            abort(403, 'Bu cari karta erişim yetkiniz yok.');
        }
    }

    private function countByRole(int $tenantId, string $role): int
    {
        return CurrentAccount::query()
            ->where('tenant_account_id', $tenantId)
            ->whereHas('roles', fn ($builder) => $builder->where('role', $role))
            ->count();
    }

    private function roleTabs(): array
    {
        return [
            ['label' => 'Tüm Cariler', 'value' => ''],
            ['label' => 'Müşteriler', 'value' => CurrentAccountRole::ROLE_CUSTOMER],
            ['label' => 'Tedarikçiler', 'value' => CurrentAccountRole::ROLE_SUPPLIER],
            ['label' => 'Fasoncular', 'value' => CurrentAccountRole::ROLE_SUBCONTRACTOR],
            ['label' => 'Kargo / Kurye', 'value' => CurrentAccountRole::ROLE_CARRIER],
            ['label' => 'Diğer', 'value' => CurrentAccountRole::ROLE_OTHER],
        ];
    }

    private function movementStatusOptions(): array
    {
        return [
            '' => 'Tümü',
            'open' => 'Açık Hareket Var',
            'none' => 'Hareket Yok',
            'overdue' => 'Vadesi Geçen Var',
        ];
    }

    private function balanceStatusOptions(): array
    {
        return [
            '' => 'Tümü',
            'receivable' => 'Borç Bakiyesi',
            'payable' => 'Alacak Bakiyesi',
            'closed' => 'Kapalı',
        ];
    }

    private function statusFilterOptions(string $activeTab): array
    {
        return match ($activeTab) {
            'arsiv' => [
                CurrentAccount::STATUS_PASSIVE => 'Pasif',
                CurrentAccount::STATUS_BLOCKED => 'Bloklu',
                CurrentAccount::STATUS_ARCHIVED => 'Arşivlendi',
            ],
            'tumu' => CurrentAccount::statusLabels(),
            default => [],
        };
    }

    private function listTabs(): array
    {
        return [
            'aktif' => 'Aktif Bakiyeler',
            'acik' => 'Açık Hareketler',
            'vadesi-gecen' => 'Vadesi Geçenler',
            'tumu' => 'Tüm Cari Bakiyeler',
            'arsiv' => 'Pasif / Arşivlenenler',
        ];
    }

    private function applyListTabScope($query, string $activeTab, int $tenantId): void
    {
        $nonCancelledStatuses = [
            CurrentAccountTransaction::STATUS_OPEN,
            CurrentAccountTransaction::STATUS_PARTIALLY_PAID,
            CurrentAccountTransaction::STATUS_PAID,
            CurrentAccountTransaction::STATUS_CLOSED,
        ];

        if ($activeTab === 'aktif') {
            $query->where('status', CurrentAccount::STATUS_ACTIVE)
                ->whereHas('transactions', function ($builder) use ($tenantId, $nonCancelledStatuses): void {
                    $builder
                        ->where('tenant_account_id', $tenantId)
                        ->whereIn('status', $nonCancelledStatuses);
                });

            return;
        }

        if ($activeTab === 'acik') {
            $query->where('status', CurrentAccount::STATUS_ACTIVE)
                ->whereHas('transactions', function ($builder) use ($tenantId): void {
                    $builder
                        ->where('tenant_account_id', $tenantId)
                        ->whereIn('status', [
                            CurrentAccountTransaction::STATUS_OPEN,
                            CurrentAccountTransaction::STATUS_PARTIALLY_PAID,
                        ]);
                });

            return;
        }

        if ($activeTab === 'vadesi-gecen') {
            $query->where('status', CurrentAccount::STATUS_ACTIVE)
                ->whereHas('transactions', function ($builder) use ($tenantId): void {
                    $builder
                        ->where('tenant_account_id', $tenantId)
                        ->whereIn('status', [
                            CurrentAccountTransaction::STATUS_OPEN,
                            CurrentAccountTransaction::STATUS_PARTIALLY_PAID,
                        ])
                        ->whereDate('due_date', '<', now()->toDateString());
                });

            return;
        }

        if ($activeTab === 'arsiv') {
            $query->whereIn('status', [
                CurrentAccount::STATUS_PASSIVE,
                CurrentAccount::STATUS_BLOCKED,
                CurrentAccount::STATUS_ARCHIVED,
            ]);

            return;
        }
    }

    private function applyMovementStatusFilter($query, string $movementStatus, int $tenantId): void
    {
        if ($movementStatus === '') {
            return;
        }

        if ($movementStatus === 'open') {
            $query->whereHas('transactions', function ($builder) use ($tenantId): void {
                $builder
                    ->where('tenant_account_id', $tenantId)
                    ->whereIn('status', [
                        CurrentAccountTransaction::STATUS_OPEN,
                        CurrentAccountTransaction::STATUS_PARTIALLY_PAID,
                    ]);
            });

            return;
        }

        if ($movementStatus === 'none') {
            $query->whereDoesntHave('transactions', function ($builder) use ($tenantId): void {
                $builder
                    ->where('tenant_account_id', $tenantId)
                    ->where(function ($statusQuery): void {
                        $statusQuery
                            ->where('status', '!=', CurrentAccountTransaction::STATUS_CANCELLED)
                            ->orWhereNull('status');
                    });
            });

            return;
        }

        if ($movementStatus === 'overdue') {
            $query->whereHas('transactions', function ($builder) use ($tenantId): void {
                $builder
                    ->where('tenant_account_id', $tenantId)
                    ->whereIn('status', [
                        CurrentAccountTransaction::STATUS_OPEN,
                        CurrentAccountTransaction::STATUS_PARTIALLY_PAID,
                    ])
                    ->whereDate('due_date', '<', now()->toDateString());
            });
        }
    }

    private function applyBalanceStatusFilter($query, string $balanceStatus, int $tenantId, bool $canViewFinancialData): void
    {
        if ($balanceStatus === '' || ! $canViewFinancialData) {
            return;
        }

        $candidateIds = (clone $query)->pluck('id')->all();
        $summaries = $this->balanceSummaryService->summarizeAccounts($tenantId, $candidateIds);

        $matchedIds = collect($summaries)
            ->filter(fn (array $summary): bool => ($summary['balance_direction'] ?? 'closed') === $balanceStatus)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($matchedIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('id', $matchedIds);
    }

    private function tabStats(int $tenantId): array
    {
        $base = CurrentAccount::query()->where('tenant_account_id', $tenantId);

        return [
            'aktif' => [
                'label' => 'Aktif Bakiyeler',
                'count' => (clone $base)
                    ->where('status', CurrentAccount::STATUS_ACTIVE)
                    ->whereHas('transactions', function ($builder) use ($tenantId): void {
                        $builder
                            ->where('tenant_account_id', $tenantId)
                            ->where('status', '!=', CurrentAccountTransaction::STATUS_CANCELLED);
                    })
                    ->count(),
            ],
            'acik' => [
                'label' => 'Açık Hareketler',
                'count' => (clone $base)
                    ->where('status', CurrentAccount::STATUS_ACTIVE)
                    ->whereHas('transactions', function ($builder) use ($tenantId): void {
                        $builder
                            ->where('tenant_account_id', $tenantId)
                            ->whereIn('status', [
                                CurrentAccountTransaction::STATUS_OPEN,
                                CurrentAccountTransaction::STATUS_PARTIALLY_PAID,
                            ]);
                    })
                    ->count(),
            ],
            'vadesi-gecen' => [
                'label' => 'Vadesi Geçenler',
                'count' => (clone $base)
                    ->where('status', CurrentAccount::STATUS_ACTIVE)
                    ->whereHas('transactions', function ($builder) use ($tenantId): void {
                        $builder
                            ->where('tenant_account_id', $tenantId)
                            ->whereIn('status', [
                                CurrentAccountTransaction::STATUS_OPEN,
                                CurrentAccountTransaction::STATUS_PARTIALLY_PAID,
                            ])
                            ->whereDate('due_date', '<', now()->toDateString());
                    })
                    ->count(),
            ],
            'tumu' => [
                'label' => 'Tüm Cari Bakiyeler',
                'count' => (clone $base)->count(),
            ],
            'arsiv' => [
                'label' => 'Pasif / Arşivlenenler',
                'count' => (clone $base)
                    ->whereIn('status', [
                        CurrentAccount::STATUS_PASSIVE,
                        CurrentAccount::STATUS_BLOCKED,
                        CurrentAccount::STATUS_ARCHIVED,
                    ])
                    ->count(),
            ],
        ];
    }

    private function sanitizeListFilters(Request $request, string $activeTab, bool $canViewFinancialData): array
    {
        $status = trim((string) $request->query('status', ''));
        $balanceStatus = trim((string) $request->query('balance_status', ''));
        $movementStatus = trim((string) $request->query('movement_status', ''));
        $filterNotice = null;

        $allowedStatusOptions = array_keys($this->statusFilterOptions($activeTab));
        if ($status !== '' && ! in_array($status, $allowedStatusOptions, true)) {
            $status = '';
            $filterNotice = 'Seçili sekmeyle uyumsuz filtre temizlendi.';
        }

        if (! $canViewFinancialData) {
            $balanceStatus = '';
        }

        if (! array_key_exists($movementStatus, $this->movementStatusOptions())) {
            $movementStatus = '';
        }

        if (! array_key_exists($balanceStatus, $this->balanceStatusOptions())) {
            $balanceStatus = '';
        }

        return [
            'search' => trim((string) $request->query('search', '')),
            'role' => trim((string) $request->query('role', '')),
            'status' => $status,
            'risk_status' => trim((string) $request->query('risk_status', '')),
            'balance_status' => $balanceStatus,
            'movement_status' => $movementStatus,
            'filter_notice' => $filterNotice,
        ];
    }

    private function formViewData(): array
    {
        return [
            'statusOptions' => CurrentAccount::statusLabels(),
            'riskStatusOptions' => CurrentAccount::riskStatusLabels(),
            'roleOptions' => [
                CurrentAccountRole::ROLE_CUSTOMER => 'Müşteri',
                CurrentAccountRole::ROLE_SUPPLIER => 'Tedarikçi',
                CurrentAccountRole::ROLE_SUBCONTRACTOR => 'Fasoncu',
                CurrentAccountRole::ROLE_CARRIER => 'Kargo / Kurye',
                CurrentAccountRole::ROLE_SERVICE_PROVIDER => 'Hizmet Sağlayıcı',
                CurrentAccountRole::ROLE_OTHER => 'Diğer',
            ],
            'currencyOptions' => [
                'TRY' => 'TRY',
                'USD' => 'USD',
                'EUR' => 'EUR',
            ],
            'supplierOptions' => Supplier::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    private function showViewData(CurrentAccount $account, bool $canViewTransactions = false): array
    {
        $links = $account->links->sortByDesc(fn (CurrentAccountLink $link) => $link->is_primary)->values();
        $companyLink = $links->firstWhere('link_type', CurrentAccountLink::LINK_COMPANY);
        $supplierLinks = $links->where('link_type', CurrentAccountLink::LINK_SUPPLIER)->values();
        $tenantSupplierAccessLinks = $links->where('link_type', CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS)->values();

        $linkedCompany = $companyLink
            ? Company::query()
                ->where('tenant_account_id', $account->tenant_account_id)
                ->find($companyLink->link_id)
            : null;

        $linkedSuppliers = Supplier::query()
            ->whereIn('id', $supplierLinks->pluck('link_id')->all())
            ->get()
            ->keyBy('id');

        $tenantSupplierAccesses = TenantSupplierAccess::query()
            ->where('tenant_account_id', $account->tenant_account_id)
            ->whereIn('id', $tenantSupplierAccessLinks->pluck('link_id')->all())
            ->get()
            ->keyBy('id');

        $transactionSummary = $canViewTransactions ? $this->transactionService->getAccountSummary($account) : null;
        $recentTransactions = $canViewTransactions
            ? $this->transactionService->getAccountTransactions($account)->limit(5)->get()
            : collect();

        return [
            'companyLink' => $companyLink,
            'linkedCompany' => $linkedCompany,
            'supplierLinks' => $supplierLinks,
            'linkedSuppliers' => $linkedSuppliers,
            'tenantSupplierAccessLinks' => $tenantSupplierAccessLinks,
            'tenantSupplierAccesses' => $tenantSupplierAccesses,
            'transactionSummary' => $transactionSummary,
            'recentTransactions' => $recentTransactions,
            'supplierOptions' => Supplier::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    private function syncSupplierLinks(CurrentAccount $account, int $tenantId, array $roles, ?int $supplierId): void
    {
        $hasSupplierRole = in_array(CurrentAccountRole::ROLE_SUPPLIER, $roles, true);

        if (!$hasSupplierRole || !$supplierId) {
            CurrentAccountLink::query()
                ->where('tenant_account_id', $tenantId)
                ->where('current_account_id', $account->id)
                ->whereIn('link_type', [
                    CurrentAccountLink::LINK_SUPPLIER,
                    CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
                ])
                ->delete();

            return;
        }

        $this->guardUniqueSupplierLink($tenantId, $supplierId, $account->id);

        CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('current_account_id', $account->id)
            ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
            ->delete();

        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenantId,
            'current_account_id' => $account->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplierId,
            'is_primary' => true,
            'meta_json' => [
                'linked_via' => 'current_account_form',
            ],
        ]);

        $tenantSupplierAccess = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenantId)
            ->where('supplier_id', $supplierId)
            ->first();

        CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('current_account_id', $account->id)
            ->where('link_type', CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS)
            ->delete();

        if ($tenantSupplierAccess) {
            CurrentAccountLink::query()->create([
                'tenant_account_id' => $tenantId,
                'current_account_id' => $account->id,
                'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
                'link_id' => $tenantSupplierAccess->id,
                'is_primary' => true,
                'meta_json' => [
                    'supplier_id' => $supplierId,
                    'linked_via' => 'current_account_form',
                ],
            ]);
        }
    }

    private function guardUniqueSupplierLink(int $tenantId, int $supplierId, int $currentAccountId): void
    {
        $alreadyLinked = CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
            ->where('link_id', $supplierId)
            ->where('current_account_id', '!=', $currentAccountId)
            ->exists();

        if ($alreadyLinked) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Bu global supplier aynı tenant içinde başka bir cari karta zaten bağlı.',
            ]);
        }
    }

    private function normalizeCurrentAccountStatusForCompany(string $status): string
    {
        return match ($status) {
            CurrentAccount::STATUS_ACTIVE => 'active',
            CurrentAccount::STATUS_BLOCKED => 'blocked',
            CurrentAccount::STATUS_PASSIVE,
            CurrentAccount::STATUS_ARCHIVED => 'inactive',
            default => 'active',
        };
    }

    private function redirectToPreferredAccountScreen(CurrentAccount $account, string $message): RedirectResponse
    {
        if ($linkedCompany = $this->resolveLinkedCompany($account)) {
            return redirect()
                ->route('admin.companies.show', $linkedCompany)
                ->with('success', $message);
        }

        return redirect()
            ->route('admin.current-accounts.show', $account)
            ->with('success', $message);
    }

    private function resolveLinkedCompany(CurrentAccount $account): ?Company
    {
        $account->loadMissing('primaryCompanyLink');

        $companyId = $account->primaryCompanyLink?->link_id;

        if (! $companyId) {
            return null;
        }

        return Company::query()
            ->where('tenant_account_id', $account->tenant_account_id)
            ->find($companyId);
    }
}
