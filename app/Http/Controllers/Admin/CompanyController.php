<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyAddress;
use App\Models\CompanyContact;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountTransaction;
use App\Models\CompanyRole;
use App\Models\Order;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderPayment;
use App\Models\Supplier;
use App\Models\SupplierProcurementRequestItem;
use App\Models\TenantSupplierAccess;
use App\Services\CurrentAccountBalanceSummaryService;
use App\Services\CurrentAccountStatementExportService;
use App\Services\CurrentAccountSyncService;
use App\Services\CurrentAccountTransactionService;
use App\Services\CompanyDuplicateResolutionService;
use App\Services\OrderPaymentCurrentAccountSyncService;
use App\Services\OrderCurrentAccountDebitSyncService;
use App\Services\SubcontractorProductionCurrentAccountSyncService;
use App\Services\SupplierProcurementCurrentAccountSyncService;
use App\Services\TenantSupplierCurrentAccountSyncService;
use App\Services\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected CurrentAccountSyncService $currentAccountSyncService,
        protected CurrentAccountBalanceSummaryService $balanceSummaryService,
        protected CurrentAccountStatementExportService $statementExportService,
        protected CurrentAccountTransactionService $currentAccountTransactionService,
        protected TenantSupplierCurrentAccountSyncService $tenantSupplierCurrentAccountSyncService,
        protected CompanyDuplicateResolutionService $companyDuplicateResolutionService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $canViewFinancialData = $request->user()?->canViewFinancialData($tenant->id) ?? false;

        $query = Company::where('tenant_account_id', $tenant->id)
            ->with([
                'companyRoles',
                'contacts',
                'addresses' => fn ($builder) => $builder
                    ->orderByDesc('is_default')
                    ->orderBy('address_type')
                    ->orderBy('title'),
            ]);

        // Apply filters
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        if ($request->filled('role')) {
            $query->byRole($request->role);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('risk_status')) {
            $query->byRiskStatus($request->risk_status);
        }

        $companyIdsForDashboard = (clone $query)->pluck('companies.id');
        $companies = $query->latest()->paginate(20);

        $linkedCurrentAccountIds = CurrentAccountLink::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('is_primary', true)
            ->whereIn('link_id', $companies->getCollection()->pluck('id'))
            ->pluck('current_account_id', 'link_id')
            ->all();

        $dashboardLinkedAccountIds = CurrentAccountLink::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('is_primary', true)
            ->whereIn('link_id', $companyIdsForDashboard)
            ->pluck('current_account_id', 'link_id')
            ->all();

        $pageBalanceSummaries = [];
        $financeDashboard = null;
        $topReceivableCompanies = [];
        $topPayableCompanies = [];
        $topOverdueCompanies = [];

        if ($canViewFinancialData) {
            $pageSummariesByAccount = $this->balanceSummaryService->summarizeAccounts(
                $tenant->id,
                array_values($linkedCurrentAccountIds)
            );

            foreach ($linkedCurrentAccountIds as $companyId => $accountId) {
                if (isset($pageSummariesByAccount[$accountId])) {
                    $pageBalanceSummaries[(int) $companyId] = $pageSummariesByAccount[$accountId];
                }
            }

            $dashboardSummariesByAccount = $this->balanceSummaryService->summarizeAccounts(
                $tenant->id,
                array_values($dashboardLinkedAccountIds)
            );

            $companyNamesById = Company::query()
                ->where('tenant_account_id', $tenant->id)
                ->whereIn('id', array_keys($dashboardLinkedAccountIds))
                ->pluck('legal_name', 'id');

            $dashboardCompanySummaries = [];
            foreach ($dashboardLinkedAccountIds as $companyId => $accountId) {
                if (! isset($dashboardSummariesByAccount[$accountId])) {
                    continue;
                }

                $summary = $dashboardSummariesByAccount[$accountId];
                $summary['company_id'] = (int) $companyId;
                $summary['company_name'] = $companyNamesById[$companyId] ?? ('Cari #' . $companyId);
                $dashboardCompanySummaries[] = $summary;
            }

            $defaultCurrency = (string) ($tenant->default_currency ?: 'TL');
            $financeDashboard = $this->balanceSummaryService->buildDashboard($dashboardCompanySummaries, $defaultCurrency);

            $topReceivableCompanies = collect($dashboardCompanySummaries)
                ->where('balance_direction', 'receivable')
                ->sortByDesc('balance_amount')
                ->take(5)
                ->values()
                ->all();

            $topPayableCompanies = collect($dashboardCompanySummaries)
                ->where('balance_direction', 'payable')
                ->sortByDesc('balance_amount')
                ->take(5)
                ->values()
                ->all();

            $topOverdueCompanies = collect($dashboardCompanySummaries)
                ->filter(fn (array $summary): bool => (int) ($summary['overdue_transaction_count'] ?? 0) > 0)
                ->sortByDesc('overdue_amount')
                ->take(5)
                ->values()
                ->all();
        }

        // Statistics
        $stats = [
            'total' => Company::where('tenant_account_id', $tenant->id)->count(),
            'customers' => Company::where('tenant_account_id', $tenant->id)->byRole('customer')->count(),
            'suppliers' => Company::where('tenant_account_id', $tenant->id)->byRole('supplier')->count(),
            'print_fason' => Company::where('tenant_account_id', $tenant->id)->byRole('print_fason')->count(),
        ];

        return view('admin.companies.index', [
            'companies' => $companies,
            'stats' => $stats,
            'linkedCurrentAccountIds' => $linkedCurrentAccountIds,
            'balanceSummaries' => $pageBalanceSummaries,
            'financeDashboard' => $financeDashboard,
            'topReceivableCompanies' => $topReceivableCompanies,
            'topPayableCompanies' => $topPayableCompanies,
            'topOverdueCompanies' => $topOverdueCompanies,
            'canViewFinancialData' => $canViewFinancialData,
            'canViewCurrentAccountTransactions' => $request->user()?->hasAnyPermissionInTenant([
                'view_current_account_transactions',
                'manage_current_account_transactions',
                'cancel_current_account_transactions',
            ], $tenant->id) ?? false,
            'filters' => [
                'search' => $request->get('search', ''),
                'role' => $request->get('role', ''),
                'status' => $request->get('status', ''),
                'risk_status' => $request->get('risk_status', ''),
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $this->guardTenantOperatorAccess(request());

        $tenant = $this->tenantResolver->getCurrentTenant(request());

        return view('admin.companies.create', [
            'identityType' => old('identity_type', 'company'),
            'supplierOptions' => $this->supplierSourceOptionsForTenant($tenant->id),
            'selectedSupplierId' => old('supplier_id'),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->guardTenantOperatorAccess($request);

        $tenant = $this->tenantResolver->getCurrentTenant($request);

        $validated = $this->validateCompanyPayload($request, $tenant->id);

        DB::beginTransaction();
        try {
            // Create company
            $company = Company::create([
                'tenant_account_id' => $tenant->id,
                'legal_name' => $validated['legal_name'],
                'short_name' => $this->cleanNullable($validated['short_name'] ?? null),
                'tax_office' => $this->cleanNullable($validated['tax_office'] ?? null),
                'tax_number' => $this->cleanNullable($validated['tax_number'] ?? null),
                'email' => $this->cleanNullable($validated['email'] ?? null),
                'phone' => $this->cleanNullable($validated['phone'] ?? null),
                'mobile' => $this->cleanNullable($validated['mobile'] ?? null),
                'website' => $this->normalizeWebsite($validated['website'] ?? null),
                'status' => $validated['status'],
                'risk_status' => $this->cleanNullable($validated['risk_status'] ?? null),
                'portal_enabled' => $validated['portal_enabled'] ?? false,
                'notes' => $this->cleanNullable($validated['notes'] ?? null),
            ]);

            // Create roles
            foreach ($validated['roles'] as $roleKey) {
                CompanyRole::create([
                    'tenant_account_id' => $tenant->id,
                    'company_id' => $company->id,
                    'role_key' => $roleKey,
                ]);
            }

            $this->createInitialBillingAddressIfNeeded($tenant->id, $company, $validated);
            $this->createInitialContactIfNeeded($tenant->id, $company, $validated);
            $linkedAccount = $this->currentAccountSyncService->ensureForCompany($company->fresh('companyRoles'));
            $this->syncSupplierSourceLinkForCompany(
                $tenant->id,
                $company->id,
                $linkedAccount,
                $validated['roles'],
                isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null
            );

            // TODO: Log audit trail
            // AuditLog::logCompanyCreated($tenant->id, $company->id, Auth::id());

            DB::commit();

            return redirect()
                ->route('admin.companies.show', $company)
                ->with('success', 'Cari kart başarıyla oluşturuldu.');

        } catch (ValidationException $exception) {
            DB::rollback();

            throw $exception;
        } catch (\Exception $e) {
            DB::rollback();
            
            return back()
                ->withInput()
                ->withErrors(['error' => 'Cari kart oluşturulurken bir hata oluştu: ' . $e->getMessage()]);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Company $company)
    {
        $this->guardTenantOperatorAccess($request);

        $tenant = $this->tenantResolver->getCurrentTenant($request);
        
        // Tenant isolation check
        if ($company->tenant_account_id !== $tenant->id) {
            abort(403, 'Bu cari karta erişim yetkiniz yok.');
        }

        $company->load([
            'companyRoles',
            'contacts' => function ($query) {
                $query->orderByDesc('is_primary')->orderBy('name');
            },
            'portalUsers.companyContact',
            'addresses' => function ($query) {
                $query->orderByDesc('is_default')->orderBy('address_type')->orderBy('title');
            },
            'customerOrders' => function ($query) {
                $query->latest()->limit(5);
            }
        ]);

        $currentAccountLink = CurrentAccountLink::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('link_id', $company->id)
            ->where('is_primary', true)
            ->first();

        $linkedCurrentAccount = null;
        if ($currentAccountLink?->current_account_id) {
            $linkedCurrentAccount = CurrentAccount::query()
                ->where('tenant_account_id', $tenant->id)
                ->with('roles')
                ->find($currentAccountLink->current_account_id);
        }

        $supplierMapping = $this->resolveSupplierMappingSummary($tenant->id, $linkedCurrentAccount);
        $duplicateSupplierSummary = $this->companyDuplicateResolutionService->auditCompanyDuplicateStatus($company);
        $canViewFinancialData = Auth::user()->canViewFinancialData($tenant->id);
        $linkedCurrentAccountSummary = null;
        $statementFilters = $this->validateCompanyStatementFilters($request);
        $statementTransactions = collect();
        $statementFilteredSummary = null;
        $statementAging = null;
        $statementRunningBalances = [];
        $statementOpeningBalance = null;
        $statementSourcePayments = collect();
        $statementSourceProcurementItems = collect();
        $statementSourceProductions = collect();
        $manualTransactionTypeOptions = [];
        $manualQuickActionDefaults = [];
        $manualFormDefaults = [];
        $manualStatusOptions = CurrentAccountTransaction::manualStatusLabels();
        $paymentMethodOptions = CurrentAccountTransaction::paymentMethodLabels();
        $orderOptions = collect();

        if ($canViewFinancialData && $linkedCurrentAccount) {
            $linkedCurrentAccountSummary = $this->balanceSummaryService
                ->summarizeAccounts($tenant->id, [$linkedCurrentAccount->id])[$linkedCurrentAccount->id] ?? null;

            $statementTransactions = $this->balanceSummaryService
                ->getStatementQuery($linkedCurrentAccount->loadMissing('roles'), $statementFilters)
                ->limit(10)
                ->get();

            $statementFilteredSummary = $this->balanceSummaryService
                ->summarizeFilteredTransactions($linkedCurrentAccount, $statementFilters);

            $statementAging = $this->balanceSummaryService
                ->buildAgingSummary($linkedCurrentAccount);

            $statementRunningBalances = $this->balanceSummaryService
                ->getRunningBalanceMap($linkedCurrentAccount);

            $statementOpeningBalance = $this->statementExportService
                ->openingBalance($linkedCurrentAccount, $statementFilters);

            $statementSourcePayments = $this->resolveSourcePayments($statementTransactions, $tenant->id);
            $statementSourceOrders = $this->resolveSourceOrders($statementTransactions, $tenant->id);
            $statementSourceProcurementItems = $this->resolveSourceProcurementItems($statementTransactions, $tenant->id);
            $statementSourceProductions = $this->resolveSourceProductions($statementTransactions, $tenant->id);
        }

        if ($linkedCurrentAccount) {
            $manualTransactionTypeOptions = $this->currentAccountTransactionService->manualTransactionTypeOptions($linkedCurrentAccount);
            $manualQuickActionDefaults = $this->currentAccountTransactionService->manualQuickActionDefaults($linkedCurrentAccount);
            $manualFormDefaults = $this->currentAccountTransactionService->manualFormDefaults(
                $linkedCurrentAccount,
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
            $orderOptions = Order::query()
                ->where('tenant_account_id', $tenant->id)
                ->latest('id')
                ->limit(50)
                ->get(['id', 'document_number', 'customer_company_id', 'status']);
        }

        return view('admin.companies.show', [
            'company' => $company,
            'canViewFinancialData' => $canViewFinancialData,
            'canViewCurrentAccountTransactions' => $request->user()?->hasAnyPermissionInTenant([
                'view_current_account_transactions',
                'manage_current_account_transactions',
                'cancel_current_account_transactions',
            ], $tenant->id) ?? false,
            'canManageCurrentAccountTransactions' => $request->user()?->hasPermissionInTenant(
                'manage_current_account_transactions',
                $tenant->id
            ) ?? false,
            'linkedCurrentAccount' => $linkedCurrentAccount,
            'linkedCurrentAccountSummary' => $linkedCurrentAccountSummary,
            'statementFilters' => $statementFilters,
            'statementTransactions' => $statementTransactions,
            'statementFilteredSummary' => $statementFilteredSummary,
            'statementAging' => $statementAging,
            'statementRunningBalances' => $statementRunningBalances,
            'statementOpeningBalance' => $statementOpeningBalance,
            'statementSourcePayments' => $statementSourcePayments,
            'statementSourceOrders' => $statementSourceOrders ?? collect(),
            'statementSourceProcurementItems' => $statementSourceProcurementItems,
            'statementSourceProductions' => $statementSourceProductions,
            'manualTransactionTypeOptions' => $manualTransactionTypeOptions,
            'manualQuickActionDefaults' => $manualQuickActionDefaults,
            'manualFormDefaults' => $manualFormDefaults,
            'manualStatusOptions' => $manualStatusOptions,
            'paymentMethodOptions' => $paymentMethodOptions,
            'orderOptions' => $orderOptions,
            'supplierMapping' => $supplierMapping,
            'duplicateSupplierSummary' => $duplicateSupplierSummary,
        ]);
    }

    public function archiveDuplicate(Request $request, Company $company)
    {
        $this->guardTenantOperatorAccess($request);

        $tenant = $this->tenantResolver->getCurrentTenant($request);

        if ($company->tenant_account_id !== $tenant->id) {
            abort(403, 'Bu cari karta erişim yetkiniz yok.');
        }

        $audit = $this->companyDuplicateResolutionService->auditCompanyDuplicateStatus($company);

        if (! $audit || ! ($audit['can_archive'] ?? false)) {
            return redirect()
                ->to(route('admin.companies.show', ['company' => $company, 'tab' => 'benzer-cari']))
                ->with('error', 'Bu cari otomatik arşivlenemez. Lütfen kontrol listesindeki bağlantıları inceleyin.');
        }

        $this->companyDuplicateResolutionService->archiveEmptyDuplicate($company, $request->user());

        return redirect()
            ->to(route('admin.companies.show', ['company' => $company, 'tab' => 'benzer-cari']))
            ->with('success', 'Benzer cari arşivlendi. Ana cari kart kullanılmaya devam edecek.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Company $company)
    {
        $this->guardTenantOperatorAccess($request);

        $tenant = $this->tenantResolver->getCurrentTenant($request);
        
        // Tenant isolation check
        if ($company->tenant_account_id !== $tenant->id) {
            abort(403, 'Bu cari karta erişim yetkiniz yok.');
        }

        $company->load('companyRoles');
        $linkedCurrentAccount = $this->resolveLinkedCurrentAccount($tenant->id, $company->id);
        $mappedSupplierId = $this->resolveMappedSupplierId($tenant->id, $linkedCurrentAccount?->id);

        return view('admin.companies.edit', [
            'company' => $company,
            'identityType' => $this->resolveIdentityType($company->tax_number),
            'supplierOptions' => $this->supplierSourceOptionsForTenant($tenant->id),
            'selectedSupplierId' => old('supplier_id', $mappedSupplierId),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Company $company)
    {
        $this->guardTenantOperatorAccess($request);

        $tenant = $this->tenantResolver->getCurrentTenant($request);
        
        // Tenant isolation check
        if ($company->tenant_account_id !== $tenant->id) {
            abort(403, 'Bu cari karta erişim yetkiniz yok.');
        }

        $validated = $this->validateCompanyPayload($request, $tenant->id);

        DB::beginTransaction();
        try {
            // Update company
            $company->update([
                'legal_name' => $validated['legal_name'],
                'short_name' => $this->cleanNullable($validated['short_name'] ?? null),
                'tax_office' => $this->cleanNullable($validated['tax_office'] ?? null),
                'tax_number' => $this->cleanNullable($validated['tax_number'] ?? null),
                'email' => $this->cleanNullable($validated['email'] ?? null),
                'phone' => $this->cleanNullable($validated['phone'] ?? null),
                'mobile' => $this->cleanNullable($validated['mobile'] ?? null),
                'website' => $this->normalizeWebsite($validated['website'] ?? null),
                'status' => $validated['status'],
                'risk_status' => $this->cleanNullable($validated['risk_status'] ?? null),
                'portal_enabled' => $validated['portal_enabled'] ?? false,
                'notes' => $this->cleanNullable($validated['notes'] ?? null),
            ]);

            // Update roles - remove existing and add new
            $company->companyRoles()->delete();
            foreach ($validated['roles'] as $roleKey) {
                CompanyRole::create([
                    'tenant_account_id' => $tenant->id,
                    'company_id' => $company->id,
                    'role_key' => $roleKey,
                ]);
            }

            $linkedAccount = $this->currentAccountSyncService->ensureForCompany($company->fresh('companyRoles'));
            $this->syncSupplierSourceLinkForCompany(
                $tenant->id,
                $company->id,
                $linkedAccount,
                $validated['roles'],
                isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null
            );

            // TODO: Log audit trail
            // AuditLog::logCompanyUpdated($tenant->id, $company->id, Auth::id());

            DB::commit();

            return redirect()
                ->route('admin.companies.show', $company)
                ->with('success', 'Cari kart başarıyla güncellendi.');

        } catch (ValidationException $exception) {
            DB::rollback();

            throw $exception;
        } catch (\Exception $e) {
            DB::rollback();
            
            return back()
                ->withInput()
                ->withErrors(['error' => 'Cari kart güncellenirken bir hata oluştu: ' . $e->getMessage()]);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Company $company)
    {
        $this->guardTenantOperatorAccess($request);

        $tenant = $this->tenantResolver->getCurrentTenant($request);
        
        // Tenant isolation check
        if ($company->tenant_account_id !== $tenant->id) {
            abort(403, 'Bu cari karta erişim yetkiniz yok.');
        }

        // TODO: Check if company has orders or other dependencies
        // For now, implement soft deactivation instead of hard delete
        try {
            $company->update(['status' => 'passive']);

            // TODO: Log audit trail
            // AuditLog::logCompanyDeactivated($tenant->id, $company->id, Auth::id());

            return redirect()
                ->route('admin.companies.index')
                ->with('success', 'Cari kart pasife alındı.');

        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Cari kart pasife alınamadı: ' . $e->getMessage()]);
        }
    }

    private function validateCompanyPayload(Request $request, int $tenantId): array
    {
        return $request->validate([
            'identity_type' => ['required', Rule::in(['company', 'person', 'sole_trader'])],
            'legal_name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:100'],
            'tax_office' => ['nullable', 'string', 'max:100'],
            'tax_number' => ['nullable', 'regex:/^\d{10,11}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                $website = trim((string) ($value ?? ''));

                if ($website === '') {
                    return;
                }

                if (! Str::startsWith(Str::lower($website), ['http://', 'https://'])) {
                    $website = 'https://' . $website;
                }

                if (! filter_var($website, FILTER_VALIDATE_URL)) {
                    $fail('Geçerli bir web sitesi adresi giriniz.');
                }
            }],
            'status' => ['required', 'in:active,passive'],
            'risk_status' => ['nullable', 'in:low,medium,high,critical'],
            'portal_enabled' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'in:customer,supplier,print_fason,production_partner,delivery_partner,other'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id', function (string $attribute, mixed $value, \Closure $fail) use ($tenantId): void {
                if (! filled($value)) {
                    return;
                }

                $hasAccess = TenantSupplierAccess::query()
                    ->where('tenant_account_id', $tenantId)
                    ->where('supplier_id', (int) $value)
                    ->where('is_active', true)
                    ->exists();

                if (! $hasAccess) {
                    $fail('Seçilen hazır ürün kaynağı bu Abone Firmada aktif değil.');
                }
            }],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'billing_city' => ['nullable', 'string', 'max:100'],
            'billing_district' => ['nullable', 'string', 'max:100'],
            'billing_country' => ['nullable', 'string', 'max:100'],
            'billing_postal_code' => ['nullable', 'string', 'max:50'],
            'primary_contact_name' => ['nullable', 'string', 'max:255', 'required_with:primary_contact_email,primary_contact_phone,primary_contact_note'],
            'primary_contact_email' => ['nullable', 'email', 'max:255'],
            'primary_contact_phone' => ['nullable', 'string', 'max:50'],
            'primary_contact_note' => ['nullable', 'string', 'max:255'],
        ], [
            'identity_type.required' => 'Cari tipi alanı zorunludur.',
            'identity_type.in' => 'Cari tipi geçersiz.',
            'legal_name.required' => 'Firma ünvanı alanı zorunludur.',
            'email.email' => 'Geçerli bir e-posta adresi giriniz.',
            'primary_contact_email.email' => 'Geçerli bir e-posta adresi giriniz.',
            'primary_contact_name.required_with' => 'Ad Soyad alanı zorunludur.',
            'tax_number.regex' => 'VKN / TCKN 10 veya 11 haneli rakamlardan oluşmalıdır.',
            'roles.required' => 'En az bir cari rolü seçiniz.',
            'roles.min' => 'En az bir cari rolü seçiniz.',
            'supplier_id.exists' => 'Seçilen hazır ürün kaynağı bulunamadı.',
        ], [
            'legal_name' => 'Firma ünvanı',
            'primary_contact_name' => 'Ad Soyad',
            'billing_address' => 'Açık adres',
        ]);
    }

    private function createInitialBillingAddressIfNeeded(int $tenantId, Company $company, array $validated): void
    {
        $hasAddressPayload = collect([
            $validated['billing_address'] ?? null,
            $validated['billing_city'] ?? null,
            $validated['billing_district'] ?? null,
            $validated['billing_postal_code'] ?? null,
        ])->contains(fn ($value) => filled($value));

        if (! $hasAddressPayload) {
            return;
        }

        $payload = [
            'tenant_account_id' => $tenantId,
            'company_id' => $company->id,
            'address_type' => 'billing',
            'title' => 'Fatura Adresi',
            'country' => filled($validated['billing_country'] ?? null) ? trim((string) $validated['billing_country']) : 'Türkiye',
            'city' => filled($validated['billing_city'] ?? null) ? trim((string) $validated['billing_city']) : null,
            'district' => filled($validated['billing_district'] ?? null) ? trim((string) $validated['billing_district']) : null,
            'address' => filled($validated['billing_address'] ?? null) ? trim((string) $validated['billing_address']) : '',
            'postal_code' => filled($validated['billing_postal_code'] ?? null) ? trim((string) $validated['billing_postal_code']) : null,
            'is_default' => true,
        ];

        CompanyAddress::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenantId,
                'company_id' => $company->id,
                'address_type' => 'billing',
                'title' => 'Fatura Adresi',
                'address' => $payload['address'],
                'postal_code' => $payload['postal_code'],
            ],
            $payload
        );
    }

    private function createInitialContactIfNeeded(int $tenantId, Company $company, array $validated): void
    {
        $hasContactPayload = collect([
            $validated['primary_contact_name'] ?? null,
            $validated['primary_contact_email'] ?? null,
            $validated['primary_contact_phone'] ?? null,
            $validated['primary_contact_note'] ?? null,
        ])->contains(fn ($value) => filled($value));

        if (! $hasContactPayload) {
            return;
        }

        $name = trim((string) ($validated['primary_contact_name'] ?? ''));

        CompanyContact::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenantId,
                'company_id' => $company->id,
                'name' => $name,
                'email' => filled($validated['primary_contact_email'] ?? null) ? trim((string) $validated['primary_contact_email']) : null,
            ],
            [
                'title' => filled($validated['primary_contact_note'] ?? null) ? trim((string) $validated['primary_contact_note']) : null,
                'phone' => filled($validated['primary_contact_phone'] ?? null) ? trim((string) $validated['primary_contact_phone']) : null,
                'mobile' => null,
                'is_primary' => true,
            ]
        );
    }

    private function resolveIdentityType(?string $taxNumber): string
    {
        return strlen((string) $taxNumber) === 11 ? 'person' : 'company';
    }

    private function supplierSourceOptionsForTenant(int $tenantId)
    {
        return TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenantId)
            ->where('is_active', true)
            ->whereHas('supplier', fn ($query) => $query->where('status', 'active'))
            ->with('supplier:id,name')
            ->get()
            ->map(fn (TenantSupplierAccess $access) => [
                'supplier_id' => $access->supplier_id,
                'name' => $access->supplier?->name ?: ('Kaynak #' . $access->supplier_id),
                'is_purchase_ready' => (bool) $access->can_request_purchase,
            ])
            ->sortBy('name')
            ->values();
    }

    private function resolveLinkedCurrentAccount(int $tenantId, int $companyId): ?CurrentAccount
    {
        $link = CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('link_id', $companyId)
            ->where('is_primary', true)
            ->first();

        if (! $link?->current_account_id) {
            return null;
        }

        return CurrentAccount::query()
            ->where('tenant_account_id', $tenantId)
            ->find($link->current_account_id);
    }

    private function resolveMappedSupplierId(int $tenantId, ?int $currentAccountId): ?int
    {
        if (! $currentAccountId) {
            return null;
        }

        $supplierId = CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('current_account_id', $currentAccountId)
            ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
            ->value('link_id');

        if ($supplierId) {
            return (int) $supplierId;
        }

        $tenantSupplierAccessId = CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('current_account_id', $currentAccountId)
            ->where('link_type', CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS)
            ->value('link_id');

        if (! $tenantSupplierAccessId) {
            return null;
        }

        return TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenantId)
            ->whereKey($tenantSupplierAccessId)
            ->value('supplier_id');
    }

    private function resolveSupplierMappingSummary(int $tenantId, ?CurrentAccount $currentAccount): ?array
    {
        $supplierId = $this->resolveMappedSupplierId($tenantId, $currentAccount?->id);

        if (! $supplierId) {
            return null;
        }

        $supplier = Supplier::query()->find($supplierId);
        $access = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenantId)
            ->where('supplier_id', $supplierId)
            ->first();

        if (! $supplier) {
            return null;
        }

        return [
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'is_active' => (bool) ($access?->is_active ?? false),
            'can_request_purchase' => (bool) ($access?->can_request_purchase ?? false),
        ];
    }

    private function resolveDuplicateSupplierSummary(
        \App\Models\TenantAccount $tenant,
        Company $company,
        ?CurrentAccount $currentAccount,
        ?array $supplierMapping,
    ): ?array {
        if (! $company->hasRole('supplier')) {
            return null;
        }

        $supplier = null;
        $supplierId = (int) ($supplierMapping['supplier_id'] ?? $this->resolveMappedSupplierId($tenant->id, $currentAccount?->id) ?? 0);
        if ($supplierId > 0) {
            $supplier = Supplier::query()->find($supplierId);
        }

        if (! $supplier) {
            $supplier = $this->resolveSupplierForDuplicateAudit($tenant->id, $company);
        }

        if (! $supplier) {
            return [
                'has_mapping' => false,
                'has_similar_companies' => false,
                'status_label' => 'Hazır ürün kaynağı eşleşmesi bulunmuyor',
                'status_tone' => 'amber',
                'current_company' => [
                    'is_main_company' => false,
                    'is_similar_company' => false,
                    'has_financial_history' => false,
                    'has_operational_links' => false,
                    'is_archive_candidate' => false,
                    'transaction_count' => 0,
                    'linked_orders_count' => 0,
                    'linked_procurements_count' => 0,
                    'linked_order_payments_count' => 0,
                    'selection_reasons' => [],
                    'repair_warnings' => [],
                ],
                'main_company' => null,
                'similar_companies' => [],
                'warnings' => [],
            ];
        }

        $audit = $this->tenantSupplierCurrentAccountSyncService->auditDuplicateSupplierCaris($tenant, $supplier);
        $currentCompanyAudit = collect($audit['companies'] ?? [])
            ->firstWhere('company.id', $company->id);
        $mainCompany = data_get($audit, 'canonical_candidate');
        $similarCompanies = collect($audit['duplicate_candidates'] ?? [])
            ->reject(fn (array $candidate): bool => (int) data_get($candidate, 'company.id') === $company->id)
            ->map(fn (array $candidate): array => [
                'id' => (int) data_get($candidate, 'company.id'),
                'name' => trim((string) (data_get($candidate, 'company.short_name') ?: data_get($candidate, 'company.legal_name') ?: ('Cari #' . data_get($candidate, 'company.id')))),
                'is_archive_candidate' => (bool) ($candidate['is_safe_link_repair_candidate'] ?? false),
                'has_financial_history' => (bool) ($candidate['has_financial_history'] ?? false),
                'has_operational_links' => (bool) ($candidate['has_operational_links'] ?? false),
            ])
            ->values()
            ->all();

        $isMainCompany = (int) data_get($mainCompany, 'company.id') === $company->id;
        $hasSimilarCompanies = count($audit['duplicate_candidates'] ?? []) > 0;
        $statusLabel = ! $hasSimilarCompanies
            ? 'Güvenli eşleşme var'
            : ($isMainCompany ? 'Ana Cari Kart olarak izleniyor' : 'Benzer cari kontrolü gerekiyor');
        $statusTone = ! $hasSimilarCompanies
            ? 'green'
            : ($isMainCompany ? 'blue' : 'amber');

        return [
            'has_mapping' => true,
            'has_similar_companies' => $hasSimilarCompanies,
            'status_label' => $statusLabel,
            'status_tone' => $statusTone,
            'main_company' => $mainCompany ? [
                'id' => (int) data_get($mainCompany, 'company.id'),
                'name' => trim((string) (data_get($mainCompany, 'company.short_name') ?: data_get($mainCompany, 'company.legal_name') ?: ('Cari #' . data_get($mainCompany, 'company.id')))),
            ] : null,
            'current_company' => [
                'is_main_company' => $isMainCompany,
                'is_similar_company' => $hasSimilarCompanies && ! $isMainCompany,
                'has_financial_history' => (bool) ($currentCompanyAudit['has_financial_history'] ?? false),
                'has_operational_links' => (bool) ($currentCompanyAudit['has_operational_links'] ?? false),
                'is_archive_candidate' => (bool) ($currentCompanyAudit['is_safe_link_repair_candidate'] ?? false),
                'transaction_count' => (int) ($currentCompanyAudit['transaction_count'] ?? 0),
                'linked_orders_count' => (int) ($currentCompanyAudit['linked_orders_count'] ?? 0),
                'linked_procurements_count' => (int) ($currentCompanyAudit['linked_procurements_count'] ?? 0),
                'linked_order_payments_count' => (int) ($currentCompanyAudit['linked_order_payments_count'] ?? 0),
                'selection_reasons' => (array) ($currentCompanyAudit['selection_reasons'] ?? []),
                'repair_warnings' => (array) ($currentCompanyAudit['repair_warnings'] ?? []),
            ],
            'similar_companies' => $similarCompanies,
            'warnings' => (array) ($audit['warnings'] ?? []),
        ];
    }

    private function resolveSupplierForDuplicateAudit(int $tenantId, Company $company): ?Supplier
    {
        $normalizedCandidates = collect([
            $company->legal_name,
            $company->short_name,
        ])
            ->filter(fn ($value): bool => filled($value))
            ->map(fn ($value): string => Str::lower(Str::squish((string) $value)))
            ->unique()
            ->values();

        if ($normalizedCandidates->isEmpty()) {
            return null;
        }

        return TenantSupplierAccess::query()
            ->with('supplier')
            ->where('tenant_account_id', $tenantId)
            ->active()
            ->get()
            ->map->supplier
            ->filter()
            ->first(function (Supplier $supplier) use ($normalizedCandidates): bool {
                $normalizedName = Str::lower(Str::squish((string) $supplier->name));

                return $normalizedCandidates->contains($normalizedName);
            });
    }

    private function syncSupplierSourceLinkForCompany(int $tenantId, int $companyId, CurrentAccount $currentAccount, array $companyRoles, ?int $supplierId): void
    {
        $hasSupplierRole = in_array('supplier', $companyRoles, true);

        if (! $hasSupplierRole) {
            CurrentAccountLink::query()
                ->where('tenant_account_id', $tenantId)
                ->where('current_account_id', $currentAccount->id)
                ->whereIn('link_type', [
                    CurrentAccountLink::LINK_SUPPLIER,
                    CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
                ])
                ->delete();

            return;
        }

        if (! $supplierId) {
            return;
        }

        $this->guardUniqueSupplierLink($tenantId, $supplierId, $currentAccount->id, $companyId);

        CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('current_account_id', $currentAccount->id)
            ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
            ->where('link_id', '!=', $supplierId)
            ->delete();

        CurrentAccountLink::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenantId,
                'link_type' => CurrentAccountLink::LINK_SUPPLIER,
                'link_id' => $supplierId,
            ],
            [
                'current_account_id' => $currentAccount->id,
                'is_primary' => true,
                'meta_json' => [
                    'linked_via' => 'company_form',
                ],
            ]
        );

        $tenantSupplierAccess = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenantId)
            ->where('supplier_id', $supplierId)
            ->first();

        CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('current_account_id', $currentAccount->id)
            ->where('link_type', CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS)
            ->when(
                $tenantSupplierAccess,
                fn ($query) => $query->where('link_id', '!=', $tenantSupplierAccess->id)
            )
            ->delete();

        if ($tenantSupplierAccess) {
            CurrentAccountLink::query()->updateOrCreate(
                [
                    'tenant_account_id' => $tenantId,
                    'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
                    'link_id' => $tenantSupplierAccess->id,
                ],
                [
                    'current_account_id' => $currentAccount->id,
                    'is_primary' => true,
                    'meta_json' => [
                        'supplier_id' => $supplierId,
                        'linked_via' => 'company_form',
                    ],
                ]
            );
        }
    }

    private function guardUniqueSupplierLink(int $tenantId, int $supplierId, int $currentAccountId, int $companyId): void
    {
        $existingLink = CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
            ->where('link_id', $supplierId)
            ->where('current_account_id', '!=', $currentAccountId)
            ->first();

        if (! $existingLink) {
            return;
        }

        $linkedCompanyId = CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('current_account_id', $existingLink->current_account_id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('is_primary', true)
            ->value('link_id');

        if (! $linkedCompanyId || (int) $linkedCompanyId === $companyId) {
            return;
        }

        $linkedCompany = Company::query()
            ->where('tenant_account_id', $tenantId)
            ->find($linkedCompanyId);

        if (! $linkedCompany || $linkedCompany->status !== 'active') {
            return;
        }

        $companyName = trim((string) ($linkedCompany->short_name ?: $linkedCompany->legal_name ?: ('Cari #' . $linkedCompany->id)));

        throw ValidationException::withMessages([
            'supplier_id' => 'Bu hazır ürün kaynağı şu Cari Kart ile eşleştirilmiş: ' . $companyName . '. Aynı hazır ürün kaynağı iki farklı Cari Kart ile eşleştirilemez. Lütfen mevcut Cari Kartı düzenleyin veya eşlemeyi kaldırın.',
        ]);
    }

    private function normalizeWebsite(?string $website): ?string
    {
        $value = $this->cleanNullable($website);

        if ($value === null) {
            return null;
        }

        if (! Str::startsWith(Str::lower($value), ['http://', 'https://'])) {
            $value = 'https://' . $value;
        }

        return $value;
    }

    private function cleanNullable(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function guardTenantOperatorAccess(Request $request): void
    {
        if ($this->tenantResolver->isCentralAdmin($request)) {
            return;
        }

        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $user = Auth::user();

        if (! $tenant || ! $user) {
            abort(403, 'Bu cari karta erişim yetkiniz yok.');
        }

        if ($user->isPlatformAdmin() || ! $user->belongsToTenant($tenant)) {
            abort(403, 'Bu cari karta erişim yetkiniz yok.');
        }
    }

    private function validateCompanyStatementFilters(Request $request): array
    {
        $validated = $request->validate([
            'statement_from' => ['nullable', 'date'],
            'statement_to' => ['nullable', 'date'],
            'statement_type' => ['nullable', Rule::in(array_keys(CurrentAccountTransaction::typeLabels()))],
            'statement_status' => ['nullable', Rule::in(['all', 'open', 'closed', 'overdue'])],
            'statement_search' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'statement_from' => $validated['statement_from'] ?? null,
            'statement_to' => $validated['statement_to'] ?? null,
            'statement_type' => $validated['statement_type'] ?? null,
            'statement_status' => $validated['statement_status'] ?? null,
            'statement_search' => $validated['statement_search'] ?? null,
            'from' => $validated['statement_from'] ?? null,
            'to' => $validated['statement_to'] ?? null,
            'type' => $validated['statement_type'] ?? null,
            'status' => $validated['statement_status'] ?? null,
            'search' => $validated['statement_search'] ?? null,
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
}
