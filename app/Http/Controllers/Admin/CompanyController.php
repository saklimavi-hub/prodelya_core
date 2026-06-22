<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyAddress;
use App\Models\CompanyContact;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\CompanyRole;
use App\Models\Supplier;
use App\Models\TenantSupplierAccess;
use App\Services\CurrentAccountSyncService;
use App\Services\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected CurrentAccountSyncService $currentAccountSyncService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        
        $query = Company::where('tenant_account_id', $tenant->id)
            ->with(['companyRoles', 'contacts']);

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

        $companies = $query->latest()->paginate(20);

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

        return view('admin.companies.show', [
            'company' => $company,
            'canViewFinancialData' => Auth::user()->canViewFinancialData($tenant->id),
            'linkedCurrentAccount' => $linkedCurrentAccount,
            'supplierMapping' => $supplierMapping,
        ]);
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
                    $fail('Seçilen hazır ürün kaynağı bu tenant için aktif değil.');
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

        return CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('current_account_id', $currentAccountId)
            ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
            ->value('link_id');
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

    private function syncSupplierSourceLinkForCompany(int $tenantId, CurrentAccount $currentAccount, array $companyRoles, ?int $supplierId): void
    {
        $hasSupplierRole = in_array('supplier', $companyRoles, true);

        if (! $hasSupplierRole || ! $supplierId) {
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

        $this->guardUniqueSupplierLink($tenantId, $supplierId, $currentAccount->id);

        CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('current_account_id', $currentAccount->id)
            ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
            ->delete();

        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenantId,
            'current_account_id' => $currentAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplierId,
            'is_primary' => true,
            'meta_json' => [
                'linked_via' => 'company_form',
            ],
        ]);

        $tenantSupplierAccess = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenantId)
            ->where('supplier_id', $supplierId)
            ->first();

        CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('current_account_id', $currentAccount->id)
            ->where('link_type', CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS)
            ->delete();

        if ($tenantSupplierAccess) {
            CurrentAccountLink::query()->create([
                'tenant_account_id' => $tenantId,
                'current_account_id' => $currentAccount->id,
                'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
                'link_id' => $tenantSupplierAccess->id,
                'is_primary' => true,
                'meta_json' => [
                    'supplier_id' => $supplierId,
                    'linked_via' => 'company_form',
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
                'supplier_id' => 'Bu hazır ürün kaynağı aynı tenant içinde başka bir cari karta zaten bağlı.',
            ]);
        }
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
}
