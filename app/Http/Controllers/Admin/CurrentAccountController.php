<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountRole;
use App\Models\Supplier;
use App\Models\TenantSupplierAccess;
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
        protected CurrentAccountTransactionService $transactionService,
        protected UsageLimitGuardService $usageLimitGuardService,
    ) {
    }

    public function index(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        $query = CurrentAccount::query()
            ->where('tenant_account_id', $tenant->id)
            ->with(['roles', 'primaryCompanyLink']);

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));

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

        if ($request->filled('role')) {
            $query->whereHas('roles', function ($builder) use ($request): void {
                $builder->where('role', (string) $request->string('role'));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        if ($request->filled('risk_status')) {
            $query->where('risk_status', (string) $request->string('risk_status'));
        }

        $accounts = $query
            ->orderBy('display_name')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total' => CurrentAccount::query()->where('tenant_account_id', $tenant->id)->count(),
            'customer' => $this->countByRole($tenant->id, CurrentAccountRole::ROLE_CUSTOMER),
            'supplier' => $this->countByRole($tenant->id, CurrentAccountRole::ROLE_SUPPLIER),
            'subcontractor' => $this->countByRole($tenant->id, CurrentAccountRole::ROLE_SUBCONTRACTOR),
            'carrier' => $this->countByRole($tenant->id, CurrentAccountRole::ROLE_CARRIER),
            'other' => $this->countByRole($tenant->id, CurrentAccountRole::ROLE_OTHER),
            'inactive' => CurrentAccount::query()
                ->where('tenant_account_id', $tenant->id)
                ->whereIn('status', [CurrentAccount::STATUS_PASSIVE, CurrentAccount::STATUS_BLOCKED])
                ->count(),
        ];

        return view('admin.current-accounts.index', [
            'accounts' => $accounts,
            'stats' => $stats,
            'filters' => [
                'search' => $request->get('search', ''),
                'role' => $request->get('role', ''),
                'status' => $request->get('status', ''),
                'risk_status' => $request->get('risk_status', ''),
            ],
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
