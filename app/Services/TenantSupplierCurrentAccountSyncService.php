<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantSupplierCurrentAccountSyncService
{
    public function __construct(
        protected CurrentAccountSyncService $currentAccountSyncService,
        protected CurrentAccountBalanceSummaryService $balanceSummaryService,
    ) {}

    /**
     * @return array{company: Company, current_account: CurrentAccount, access: ?TenantSupplierAccess}
     */
    public function syncForTenantSupplierAccess(TenantAccount $tenant, Supplier $supplier): array
    {
        return DB::transaction(function () use ($tenant, $supplier): array {
            $access = TenantSupplierAccess::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('supplier_id', $supplier->id)
                ->first();

            $account = $this->resolveLinkedAccount($tenant->id, $supplier->id, $access?->id);
            $company = null;
            $matchingCompany = $this->resolveMatchingCompany($tenant->id, $supplier);

            if ($account) {
                $company = $this->resolveLinkedCompany($account);

                if (! $company && $matchingCompany) {
                    $preferredAccount = $this->resolvePrimaryCompanyAccount($matchingCompany);

                    if ($preferredAccount) {
                        $company = $matchingCompany;
                        $account = $preferredAccount;
                    }
                }

                if ($company) {
                    $this->enrichCompanyFromSupplier($company, $supplier);
                    $this->ensureCompanyRole($company, 'supplier');
                    $account = $this->currentAccountSyncService->ensureForCompany($company->fresh('companyRoles'));
                } else {
                    $this->enrichAccountFromSupplier($account, $supplier, $tenant);
                    $roles = $this->mergedAccountRoles($account, CurrentAccountRole::ROLE_SUPPLIER);
                    $this->currentAccountSyncService->syncRoles($account, $roles);
                    $company = $this->currentAccountSyncService->ensureCompanyForCurrentAccount(
                        $account->fresh('roles'),
                        $roles
                    );
                }
            } else {
                $company = $matchingCompany;

                if ($company) {
                    $this->enrichCompanyFromSupplier($company, $supplier);
                    $this->ensureCompanyRole($company, 'supplier');
                    $account = $this->currentAccountSyncService->ensureForCompany($company->fresh('companyRoles'));
                } else {
                    $account = $this->currentAccountSyncService->ensureForSupplier($supplier, $tenant);
                    $this->enrichAccountFromSupplier($account, $supplier, $tenant);
                    $roles = $this->mergedAccountRoles($account, CurrentAccountRole::ROLE_SUPPLIER);
                    $this->currentAccountSyncService->syncRoles($account, $roles);
                    $company = $this->currentAccountSyncService->ensureCompanyForCurrentAccount(
                        $account->fresh('roles'),
                        $roles
                    );
                }
            }

            $company ??= $this->currentAccountSyncService->ensureCompanyForCurrentAccount(
                $account->fresh('roles'),
                $this->mergedAccountRoles($account, CurrentAccountRole::ROLE_SUPPLIER)
            );

            if (! $company) {
                $company = $this->createCompanyForSupplierAccount($account, $supplier);
                $this->ensureCompanyRole($company, 'supplier');
                $account = $this->currentAccountSyncService->ensureForCompany($company->fresh('companyRoles'));
            }

            if (! $company->hasRole('supplier')) {
                $this->ensureCompanyRole($company, 'supplier');
                $account = $this->currentAccountSyncService->ensureForCompany($company->fresh('companyRoles'));
            }

            $this->linkSupplier($tenant->id, $account, $supplier->id);
            $this->currentAccountSyncService->linkTenantSupplierAccessIfExists($account, $supplier, $tenant);
            $this->enrichAccountFromSupplier($account, $supplier, $tenant);

            return [
                'company' => $company->fresh('companyRoles'),
                'current_account' => $account->fresh(['roles', 'links']),
                'access' => $access?->fresh(),
            ];
        });
    }

    public function repairActiveAccesses(TenantAccount $tenant, bool $missingOnly = true): Collection
    {
        return TenantSupplierAccess::query()
            ->with('supplier')
            ->where('tenant_account_id', $tenant->id)
            ->active()
            ->get()
            ->filter(function (TenantSupplierAccess $access) use ($missingOnly): bool {
                if (! $access->supplier) {
                    return false;
                }

                return ! $missingOnly || $this->accessNeedsRepair($access);
            })
            ->map(fn (TenantSupplierAccess $access): array => $this->syncForTenantSupplierAccess($tenant, $access->supplier))
            ->values();
    }

    public function auditDuplicateSupplierCaris(TenantAccount $tenant, Supplier $supplier): array
    {
        $access = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('supplier_id', $supplier->id)
            ->first();

        $candidates = $this->collectSupplierCandidateCompanies($tenant, $supplier, $access);
        $audits = $this->buildCompanyAudits($tenant, $supplier, $access, $candidates);
        $selection = $this->selectCanonicalCandidate($audits);
        $procurementCompany = $this->resolveLinkedCompanyForSupplierLookup($tenant->id, $supplier->id, $access?->id);

        return [
            'tenant' => $tenant->only(['id', 'name', 'slug']),
            'supplier' => $supplier->only(['id', 'name', 'status', 'code']),
            'tenant_supplier_access' => $access?->only([
                'id',
                'tenant_account_id',
                'supplier_id',
                'is_active',
                'can_request_purchase',
                'can_view_products',
                'created_at',
                'updated_at',
            ]),
            'link_supplier' => $this->supplierLinksForTenant($tenant->id, $supplier->id),
            'link_tenant_supplier_access' => $access
                ? $this->tenantSupplierAccessLinksForTenant($tenant->id, $access->id)
                : [],
            'companies' => $audits->values()->all(),
            'canonical_candidate' => $selection['canonical_candidate'],
            'duplicate_candidates' => $selection['duplicate_candidates'],
            'is_ambiguous' => $selection['is_ambiguous'],
            'warnings' => $selection['warnings'],
            'procurement_lookup_company_id' => $procurementCompany?->id,
            'procurement_lookup_company_name' => $procurementCompany
                ? trim((string) ($procurementCompany->short_name ?: $procurementCompany->legal_name))
                : null,
        ];
    }

    public function repairDuplicateSupplierCariLinks(
        TenantAccount $tenant,
        Supplier $supplier,
        Company|int $canonicalCompany,
        bool $dryRun = false,
    ): array {
        $canonicalCompany = $canonicalCompany instanceof Company
            ? $canonicalCompany->fresh('companyRoles')
            : Company::query()
                ->where('tenant_account_id', $tenant->id)
                ->with('companyRoles')
                ->findOrFail((int) $canonicalCompany);

        $audit = $this->auditDuplicateSupplierCaris($tenant, $supplier);
        $companyAudits = collect($audit['companies']);
        $canonicalAudit = $companyAudits->firstWhere('company.id', $canonicalCompany->id);

        if (! $canonicalAudit) {
            throw new \InvalidArgumentException('Seçilen canonical cari bu tedarikçi için audit adayları arasında bulunamadı.');
        }

        $duplicateAudits = $companyAudits
            ->reject(fn (array $companyAudit): bool => (int) data_get($companyAudit, 'company.id') === $canonicalCompany->id)
            ->values();

        $blockingDuplicates = $duplicateAudits
            ->filter(fn (array $companyAudit): bool => ! ((bool) ($companyAudit['is_safe_link_repair_candidate'] ?? false)))
            ->values();

        $warnings = collect($audit['warnings'])
            ->merge($blockingDuplicates->flatMap(fn (array $companyAudit): array => (array) ($companyAudit['repair_warnings'] ?? [])))
            ->unique()
            ->values();

        $report = [
            'canonical_company_id' => $canonicalCompany->id,
            'canonical_company_name' => trim((string) ($canonicalCompany->short_name ?: $canonicalCompany->legal_name)),
            'dry_run' => $dryRun,
            'performed' => false,
            'moved_supplier_link' => false,
            'moved_access_link' => false,
            'moved_link_count' => 0,
            'duplicate_company_ids' => $duplicateAudits->pluck('company.id')->map(fn ($id) => (int) $id)->all(),
            'warnings' => $warnings->all(),
            'blocked_by_risk' => $blockingDuplicates->isNotEmpty(),
            'risk_companies' => $blockingDuplicates->pluck('company.id')->map(fn ($id) => (int) $id)->all(),
        ];

        if ($blockingDuplicates->isNotEmpty()) {
            return $report;
        }

        $canonicalAccount = $this->resolvePrimaryCompanyAccount($canonicalCompany)
            ?? $this->currentAccountSyncService->ensureForCompany($canonicalCompany);

        if ($dryRun) {
            $report['moved_supplier_link'] = $duplicateAudits->contains(fn (array $audit): bool => (int) ($audit['linked_supplier_count'] ?? 0) > 0);
            $report['moved_access_link'] = $duplicateAudits->contains(fn (array $audit): bool => (int) ($audit['linked_supplier_access_count'] ?? 0) > 0);
            $report['moved_link_count'] = ($report['moved_supplier_link'] ? 1 : 0) + ($report['moved_access_link'] ? 1 : 0);

            return $report;
        }

        DB::transaction(function () use ($tenant, $supplier, $canonicalCompany, $canonicalAccount, &$report): void {
            if (! $canonicalCompany->hasRole('supplier')) {
                $this->ensureCompanyRole($canonicalCompany, 'supplier');
                $canonicalAccount = $this->currentAccountSyncService->ensureForCompany($canonicalCompany->fresh('companyRoles'));
            }

            $this->currentAccountSyncService->syncRoles(
                $canonicalAccount,
                $this->mergedAccountRoles($canonicalAccount, CurrentAccountRole::ROLE_SUPPLIER)
            );

            $report['moved_supplier_link'] = CurrentAccountLink::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
                ->where('link_id', $supplier->id)
                ->where('current_account_id', '!=', $canonicalAccount->id)
                ->exists();

            CurrentAccountLink::query()->updateOrCreate(
                [
                    'tenant_account_id' => $tenant->id,
                    'link_type' => CurrentAccountLink::LINK_SUPPLIER,
                    'link_id' => $supplier->id,
                ],
                [
                    'current_account_id' => $canonicalAccount->id,
                    'is_primary' => true,
                    'meta_json' => [
                        'linked_via' => 'duplicate_supplier_cari_repair',
                    ],
                ]
            );

            CurrentAccountLink::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
                ->where('link_id', $supplier->id)
                ->where('current_account_id', '!=', $canonicalAccount->id)
                ->delete();

            $access = TenantSupplierAccess::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('supplier_id', $supplier->id)
                ->first();

            if ($access) {
                $report['moved_access_link'] = CurrentAccountLink::query()
                    ->where('tenant_account_id', $tenant->id)
                    ->where('link_type', CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS)
                    ->where('link_id', $access->id)
                    ->where('current_account_id', '!=', $canonicalAccount->id)
                    ->exists();

                CurrentAccountLink::query()->updateOrCreate(
                    [
                        'tenant_account_id' => $tenant->id,
                        'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
                        'link_id' => $access->id,
                    ],
                    [
                        'current_account_id' => $canonicalAccount->id,
                        'is_primary' => false,
                        'meta_json' => [
                            'supplier_id' => $supplier->id,
                            'linked_via' => 'duplicate_supplier_cari_repair',
                        ],
                    ]
                );

                CurrentAccountLink::query()
                    ->where('tenant_account_id', $tenant->id)
                    ->where('link_type', CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS)
                    ->where('link_id', $access->id)
                    ->where('current_account_id', '!=', $canonicalAccount->id)
                    ->delete();
            }

            $report['moved_link_count'] = ($report['moved_supplier_link'] ? 1 : 0) + ($report['moved_access_link'] ? 1 : 0);
            $report['performed'] = true;
        });

        return $report;
    }

    private function resolveLinkedAccount(int $tenantId, int $supplierId, ?int $accessId): ?CurrentAccount
    {
        $query = CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where(function ($builder) use ($supplierId, $accessId): void {
                $builder->where(function ($linkQuery) use ($supplierId): void {
                    $linkQuery->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
                        ->where('link_id', $supplierId);
                });

                if ($accessId) {
                    $builder->orWhere(function ($linkQuery) use ($accessId): void {
                        $linkQuery->where('link_type', CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS)
                            ->where('link_id', $accessId);
                    });
                }
            })
            ->orderByDesc('is_primary')
            ->first();

        if (! $query?->current_account_id) {
            return null;
        }

        return CurrentAccount::query()
            ->where('tenant_account_id', $tenantId)
            ->with('roles')
            ->find($query->current_account_id);
    }

    private function resolveLinkedCompany(CurrentAccount $account): ?Company
    {
        $companyLink = CurrentAccountLink::query()
            ->where('tenant_account_id', $account->tenant_account_id)
            ->where('current_account_id', $account->id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('is_primary', true)
            ->first();

        if (! $companyLink) {
            return null;
        }

        return Company::query()
            ->where('tenant_account_id', $account->tenant_account_id)
            ->with('companyRoles')
            ->find($companyLink->link_id);
    }

    private function resolveLinkedCompanyForSupplierLookup(int $tenantId, int $supplierId, ?int $accessId): ?Company
    {
        $account = $this->resolveLinkedAccount($tenantId, $supplierId, $accessId);

        if (! $account) {
            return null;
        }

        return $this->resolveLinkedCompany($account);
    }

    private function resolvePrimaryCompanyAccount(Company $company): ?CurrentAccount
    {
        $companyLink = CurrentAccountLink::query()
            ->where('tenant_account_id', $company->tenant_account_id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('link_id', $company->id)
            ->where('is_primary', true)
            ->first();

        if (! $companyLink?->current_account_id) {
            return null;
        }

        return CurrentAccount::query()
            ->where('tenant_account_id', $company->tenant_account_id)
            ->with('roles')
            ->find($companyLink->current_account_id);
    }

    private function resolveMatchingCompany(int $tenantId, Supplier $supplier): ?Company
    {
        $taxNumber = $this->supplierTaxNumber($supplier);

        if ($taxNumber) {
            $companies = Company::query()
                ->where('tenant_account_id', $tenantId)
                ->where('tax_number', $taxNumber)
                ->with('companyRoles')
                ->get()
                ->filter(fn (Company $company): bool => ! $this->companyHasForeignSupplierLink($company, $supplier->id))
                ->values();

            if ($companies->count() === 1) {
                return $companies->first();
            }
        }

        $normalizedSupplierName = $this->normalizeName($supplier->name);

        if ($normalizedSupplierName === '') {
            return null;
        }

        $companies = Company::query()
            ->where('tenant_account_id', $tenantId)
            ->with('companyRoles')
            ->get()
            ->filter(function (Company $company) use ($normalizedSupplierName, $supplier): bool {
                $candidateNames = [
                    $company->legal_name,
                    $company->short_name,
                ];

                return collect($candidateNames)
                    ->filter()
                    ->contains(fn (?string $candidate): bool => $this->normalizeName($candidate) === $normalizedSupplierName)
                    && ! $this->companyHasForeignSupplierLink($company, $supplier->id);
            })
            ->values();

        if ($companies->count() !== 1) {
            return null;
        }

        return $companies->first();
    }

    private function companyHasForeignSupplierLink(Company $company, int $supplierId): bool
    {
        $accountLink = CurrentAccountLink::query()
            ->where('tenant_account_id', $company->tenant_account_id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('link_id', $company->id)
            ->where('is_primary', true)
            ->first();

        if (! $accountLink?->current_account_id) {
            return false;
        }

        return CurrentAccountLink::query()
            ->where('tenant_account_id', $company->tenant_account_id)
            ->where('current_account_id', $accountLink->current_account_id)
            ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
            ->where('link_id', '!=', $supplierId)
            ->exists();
    }

    private function enrichCompanyFromSupplier(Company $company, Supplier $supplier): void
    {
        $company->fill([
            'legal_name' => $company->legal_name ?: $supplier->name,
            'email' => $company->email ?: $supplier->contact_email,
            'phone' => $company->phone ?: $supplier->contact_phone,
            'website' => $company->website ?: $supplier->website,
            'tax_number' => $company->tax_number ?: $this->supplierTaxNumber($supplier),
            'tax_office' => $company->tax_office ?: $this->supplierTaxOffice($supplier),
            'status' => $company->status ?: 'active',
        ]);

        if ($company->isDirty()) {
            $company->save();
        }
    }

    private function enrichAccountFromSupplier(CurrentAccount $account, Supplier $supplier, TenantAccount $tenant): void
    {
        $account->fill([
            'display_name' => $account->display_name ?: trim((string) ($supplier->name ?: ('Tedarikçi #' . $supplier->id))),
            'legal_name' => $account->legal_name ?: $supplier->name,
            'email' => $account->email ?: $supplier->contact_email,
            'phone' => $account->phone ?: $supplier->contact_phone,
            'website' => $account->website ?: $supplier->website,
            'tax_number' => $account->tax_number ?: $this->supplierTaxNumber($supplier),
            'tax_office' => $account->tax_office ?: $this->supplierTaxOffice($supplier),
            'default_currency' => $account->default_currency ?: ($tenant->default_currency ?: 'TRY'),
            'status' => $account->status ?: CurrentAccount::STATUS_ACTIVE,
        ]);

        if (! $account->created_by && Auth::id()) {
            $account->created_by = Auth::id();
        }

        if (Auth::id()) {
            $account->updated_by = Auth::id();
        }

        if ($account->isDirty()) {
            $account->save();
        }
    }

    private function ensureCompanyRole(Company $company, string $roleKey): void
    {
        $company->companyRoles()->updateOrCreate(
            ['role_key' => $roleKey],
            ['tenant_account_id' => $company->tenant_account_id]
        );
    }

    private function createCompanyForSupplierAccount(CurrentAccount $account, Supplier $supplier): Company
    {
        return Company::query()->create([
            'tenant_account_id' => $account->tenant_account_id,
            'legal_name' => $account->legal_name ?: $supplier->name,
            'short_name' => $account->short_name,
            'tax_office' => $account->tax_office ?: $this->supplierTaxOffice($supplier),
            'tax_number' => $account->tax_number ?: $this->supplierTaxNumber($supplier),
            'email' => $account->email ?: $supplier->contact_email,
            'phone' => $account->phone ?: $supplier->contact_phone,
            'mobile' => $account->mobile,
            'website' => $account->website ?: $supplier->website,
            'status' => $account->status === CurrentAccount::STATUS_PASSIVE ? 'passive' : 'active',
            'risk_status' => $account->risk_status,
            'portal_enabled' => false,
            'notes' => $account->notes,
        ]);
    }

    /**
     * @return list<string>
     */
    private function mergedAccountRoles(CurrentAccount $account, string $requiredRole): array
    {
        $roles = $account->relationLoaded('roles')
            ? $account->roles->pluck('role')->all()
            : $account->roles()->pluck('role')->all();

        return collect($roles)
            ->push($requiredRole)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function linkSupplier(int $tenantId, CurrentAccount $account, int $supplierId): void
    {
        CurrentAccountLink::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenantId,
                'link_type' => CurrentAccountLink::LINK_SUPPLIER,
                'link_id' => $supplierId,
            ],
            [
                'current_account_id' => $account->id,
                'is_primary' => true,
                'meta_json' => [
                    'linked_via' => 'tenant_supplier_access',
                ],
            ]
        );
    }

    private function supplierTaxNumber(Supplier $supplier): ?string
    {
        $candidates = [
            data_get($supplier->config, 'tax_number'),
            data_get($supplier->config, 'vkn'),
            data_get($supplier->config, 'tckn'),
            data_get($supplier->config, 'tax_no'),
        ];

        foreach ($candidates as $candidate) {
            $value = preg_replace('/\D+/', '', (string) $candidate);

            if (in_array(strlen((string) $value), [10, 11], true)) {
                return $value;
            }
        }

        return null;
    }

    private function supplierTaxOffice(Supplier $supplier): ?string
    {
        $value = trim((string) data_get($supplier->config, 'tax_office', ''));

        return $value !== '' ? $value : null;
    }

    private function normalizeName(?string $value): string
    {
        $text = Str::of((string) $value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();

        return $text;
    }

    private function collectSupplierCandidateCompanies(TenantAccount $tenant, Supplier $supplier, ?TenantSupplierAccess $access): Collection
    {
        $companies = collect();
        $linkedAccountIds = CurrentAccountLink::query()
            ->where('tenant_account_id', $tenant->id)
            ->where(function ($query) use ($supplier, $access): void {
                $query->where(function ($supplierQuery) use ($supplier): void {
                    $supplierQuery->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
                        ->where('link_id', $supplier->id);
                });

                if ($access) {
                    $query->orWhere(function ($accessQuery) use ($access): void {
                        $accessQuery->where('link_type', CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS)
                            ->where('link_id', $access->id);
                    });
                }
            })
            ->pluck('current_account_id')
            ->filter()
            ->unique()
            ->values();

        if ($linkedAccountIds->isNotEmpty()) {
            $linkedCompanyIds = CurrentAccountLink::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('link_type', CurrentAccountLink::LINK_COMPANY)
                ->whereIn('current_account_id', $linkedAccountIds->all())
                ->pluck('link_id')
                ->filter()
                ->unique();

            if ($linkedCompanyIds->isNotEmpty()) {
                $companies = $companies->merge(
                    Company::query()
                        ->where('tenant_account_id', $tenant->id)
                        ->whereIn('id', $linkedCompanyIds->all())
                        ->get()
                );
            }
        }

        $taxNumber = $this->supplierTaxNumber($supplier);
        if ($taxNumber) {
            $companies = $companies->merge(
                Company::query()
                    ->where('tenant_account_id', $tenant->id)
                    ->where('tax_number', $taxNumber)
                    ->get()
            );
        }

        $normalizedSupplierName = $this->normalizeName($supplier->name);
        if ($normalizedSupplierName !== '') {
            $nameMatched = Company::query()
                ->where('tenant_account_id', $tenant->id)
                ->get()
                ->filter(function (Company $company) use ($normalizedSupplierName): bool {
                    return collect([$company->legal_name, $company->short_name])
                        ->filter()
                        ->contains(fn (?string $candidate): bool => $this->normalizeName($candidate) === $normalizedSupplierName);
                });

            $companies = $companies->merge($nameMatched);
        }

        return $companies
            ->unique('id')
            ->values();
    }

    private function buildCompanyAudits(
        TenantAccount $tenant,
        Supplier $supplier,
        ?TenantSupplierAccess $access,
        Collection $companies,
    ): Collection {
        $companyLinks = CurrentAccountLink::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->whereIn('link_id', $companies->pluck('id')->all())
            ->get()
            ->groupBy('link_id');

        $accountIds = $companyLinks
            ->flatten(1)
            ->pluck('current_account_id')
            ->filter()
            ->unique()
            ->values();

        $accounts = CurrentAccount::query()
            ->where('tenant_account_id', $tenant->id)
            ->with('roles')
            ->whereIn('id', $accountIds->all())
            ->get()
            ->keyBy('id');

        $accountLinks = CurrentAccountLink::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('current_account_id', $accountIds->all())
            ->get()
            ->groupBy('current_account_id');

        $summaries = $this->balanceSummaryService->summarizeAccounts($tenant->id, $accountIds->all());
        $transactionsByAccount = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('current_account_id', $accountIds->all())
            ->get()
            ->groupBy('current_account_id');

        return $companies
            ->map(function (Company $company) use ($accountLinks, $accounts, $companyLinks, $summaries, $transactionsByAccount): array {
                $company->loadMissing(['companyRoles', 'contacts', 'addresses', 'portalUsers']);

                $primaryCompanyLink = $companyLinks->get($company->id)?->firstWhere('is_primary', true)
                    ?? $companyLinks->get($company->id)?->first();
                $account = $primaryCompanyLink ? $accounts->get($primaryCompanyLink->current_account_id) : null;
                $links = $account ? $accountLinks->get($account->id, collect()) : collect();
                $transactions = $account ? $transactionsByAccount->get($account->id, collect()) : collect();
                $summary = $account ? ($summaries[$account->id] ?? null) : null;

                $transactionCount = (int) $transactions->count();
                $linkedOrdersCount = (int) $transactions->where('source_type', 'order')->count();
                $linkedOrderPaymentsCount = (int) $transactions->where('source_type', 'order_payment')->count();
                $linkedProcurementsCount = (int) $transactions->where('source_type', SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE)->count();
                $linkedProductionsCount = (int) $transactions->where('source_type', SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE)->count();
                $portalUsersCount = (int) $company->portalUsers->count();
                $addressesCount = (int) $company->addresses->count();
                $contactsCount = (int) $company->contacts->count();
                $safeLinkRepairCandidate = $transactionCount === 0
                    && $linkedOrdersCount === 0
                    && $linkedOrderPaymentsCount === 0
                    && $linkedProcurementsCount === 0
                    && $linkedProductionsCount === 0
                    && $portalUsersCount === 0
                    && $addressesCount === 0
                    && $contactsCount === 0;

                return [
                    'company' => $company->only(['id', 'legal_name', 'short_name', 'tax_number', 'status', 'created_at', 'updated_at']),
                    'roles' => $company->companyRoles->pluck('role_key')->values()->all(),
                    'portal_users_count' => $portalUsersCount,
                    'addresses_count' => $addressesCount,
                    'contacts_count' => $contactsCount,
                    'current_account' => $account?->only([
                        'id',
                        'display_name',
                        'legal_name',
                        'short_name',
                        'tax_number',
                        'status',
                        'created_at',
                        'updated_at',
                    ]),
                    'current_account_roles' => $account?->roles?->pluck('role')->values()->all() ?? [],
                    'current_account_links' => $links
                        ->map(fn (CurrentAccountLink $link): array => $link->only(['id', 'link_type', 'link_id', 'is_primary', 'meta_json']))
                        ->values()
                        ->all(),
                    'current_account_balance' => $summary['formatted_balance'] ?? null,
                    'balance_summary' => $summary,
                    'transaction_count' => $transactionCount,
                    'open_transaction_count' => (int) ($summary['open_transaction_count'] ?? 0),
                    'linked_orders_count' => $linkedOrdersCount,
                    'linked_order_payments_count' => $linkedOrderPaymentsCount,
                    'linked_procurements_count' => $linkedProcurementsCount,
                    'linked_productions_count' => $linkedProductionsCount,
                    'linked_supplier_access_count' => (int) $links->where('link_type', CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS)->count(),
                    'linked_supplier_count' => (int) $links->where('link_type', CurrentAccountLink::LINK_SUPPLIER)->count(),
                    'transaction_source_breakdown' => $transactions->groupBy('source_type')->map->count()->toArray(),
                    'info_completeness_score' => $this->companyInfoCompletenessScore($company, $account),
                    'has_financial_history' => $transactionCount > 0,
                    'has_operational_links' => ($linkedOrdersCount + $linkedOrderPaymentsCount + $linkedProcurementsCount + $linkedProductionsCount) > 0,
                    'is_safe_link_repair_candidate' => $safeLinkRepairCandidate,
                    'repair_warnings' => $safeLinkRepairCandidate
                        ? []
                        : ['Bu duplicate cari boş değil; yalnız link taşıma için güvenli aday sayılmadı.'],
                ];
            })
            ->values();
    }

    private function selectCanonicalCandidate(Collection $audits): array
    {
        if ($audits->isEmpty()) {
            return [
                'canonical_candidate' => null,
                'duplicate_candidates' => [],
                'is_ambiguous' => true,
                'warnings' => ['Cari adayı bulunamadı.'],
            ];
        }

        $sorted = $audits->sort(fn (array $left, array $right): int => $this->compareCandidateAudit($left, $right))->values();
        $canonical = $sorted->first();
        $second = $sorted->get(1);
        $isAmbiguous = $second ? $this->candidatePriorityTuple($canonical) === $this->candidatePriorityTuple($second) : false;
        $reasons = $this->buildCanonicalReasons($canonical, $second);
        $canonical['selection_reasons'] = $reasons;

        $warnings = collect();
        if ($isAmbiguous) {
            $warnings->push('Canonical cari seçimi belirsiz. Otomatik repair yerine kullanıcı onaylı değerlendirme önerilir.');
        }

        foreach ($sorted->slice(1) as $duplicate) {
            if ((int) ($duplicate['transaction_count'] ?? 0) > 0) {
                $warnings->push('Duplicate adaylardan en az birinde finans hareketi var; otomatik merge yapılmadı.');
            }
        }

        return [
            'canonical_candidate' => $canonical,
            'duplicate_candidates' => $sorted->slice(1)->values()->all(),
            'is_ambiguous' => $isAmbiguous,
            'warnings' => $warnings->unique()->values()->all(),
        ];
    }

    private function compareCandidateAudit(array $left, array $right): int
    {
        $leftTuple = $this->candidatePriorityTuple($left);
        $rightTuple = $this->candidatePriorityTuple($right);

        foreach (array_keys($leftTuple) as $key) {
            if ($leftTuple[$key] === $rightTuple[$key]) {
                continue;
            }

            return $leftTuple[$key] < $rightTuple[$key] ? 1 : -1;
        }

        return 0;
    }

    private function candidatePriorityTuple(array $audit): array
    {
        $companyCreatedAt = data_get($audit, 'company.created_at');

        return [
            'financial_history' => (int) (($audit['transaction_count'] ?? 0) > 0),
            'transaction_count' => (int) ($audit['transaction_count'] ?? 0),
            'operational_links' => (int) (($audit['linked_orders_count'] ?? 0) + ($audit['linked_order_payments_count'] ?? 0) + ($audit['linked_procurements_count'] ?? 0) + ($audit['linked_productions_count'] ?? 0)),
            'older_record' => $companyCreatedAt ? max(time() - strtotime((string) $companyCreatedAt), 0) : 0,
            'info_score' => (int) ($audit['info_completeness_score'] ?? 0),
            'link_score' => (int) (($audit['linked_supplier_count'] ?? 0) + ($audit['linked_supplier_access_count'] ?? 0)),
            'company_id_tiebreak' => -((int) data_get($audit, 'company.id')),
        ];
    }

    private function buildCanonicalReasons(array $canonical, ?array $second): array
    {
        $reasons = [];

        if (($canonical['transaction_count'] ?? 0) > 0 && (($second['transaction_count'] ?? 0) < ($canonical['transaction_count'] ?? 0))) {
            $reasons[] = 'Finans hareketi olan cari önceliklendirildi.';
        }

        $canonicalLinks = (int) (($canonical['linked_orders_count'] ?? 0) + ($canonical['linked_order_payments_count'] ?? 0) + ($canonical['linked_procurements_count'] ?? 0) + ($canonical['linked_productions_count'] ?? 0));
        $secondLinks = (int) (($second['linked_orders_count'] ?? 0) + ($second['linked_order_payments_count'] ?? 0) + ($second['linked_procurements_count'] ?? 0) + ($second['linked_productions_count'] ?? 0));
        if ($canonicalLinks > 0 && $canonicalLinks > $secondLinks) {
            $reasons[] = 'Sipariş / tedarik / üretim bağlantısı olan cari önceliklendirildi.';
        }

        $canonicalCreatedAt = data_get($canonical, 'company.created_at');
        $secondCreatedAt = data_get($second, 'company.created_at');
        if ($canonicalCreatedAt && $secondCreatedAt && strtotime((string) $canonicalCreatedAt) < strtotime((string) $secondCreatedAt)) {
            $reasons[] = 'Daha eski ve ana kayıt görünümündeki cari önceliklendirildi.';
        }

        if (($canonical['info_completeness_score'] ?? 0) > ($second['info_completeness_score'] ?? 0)) {
            $reasons[] = 'Bilgi doluluğu daha yüksek olan cari önceliklendirildi.';
        }

        if ($reasons === []) {
            $reasons[] = 'Mevcut kural setine göre en güvenli canonical aday seçildi.';
        }

        return $reasons;
    }

    private function companyInfoCompletenessScore(Company $company, ?CurrentAccount $account): int
    {
        return collect([
            $company->tax_number,
            $company->tax_office,
            $company->email,
            $company->phone,
            $company->mobile,
            $company->website,
            $company->short_name,
            $account?->tax_number,
            $account?->tax_office,
            $account?->email,
            $account?->phone,
            $account?->mobile,
            $account?->website,
        ])->filter(fn ($value): bool => filled($value))->count()
            + $company->addresses()->count()
            + $company->contacts()->count();
    }

    private function supplierLinksForTenant(int $tenantId, int $supplierId): array
    {
        return CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
            ->where('link_id', $supplierId)
            ->get(['id', 'current_account_id', 'link_id', 'is_primary', 'meta_json'])
            ->toArray();
    }

    private function tenantSupplierAccessLinksForTenant(int $tenantId, int $accessId): array
    {
        return CurrentAccountLink::query()
            ->where('tenant_account_id', $tenantId)
            ->where('link_type', CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS)
            ->where('link_id', $accessId)
            ->get(['id', 'current_account_id', 'link_id', 'is_primary', 'meta_json'])
            ->toArray();
    }

    private function accessNeedsRepair(TenantSupplierAccess $access): bool
    {
        $account = $this->resolveLinkedAccount($access->tenant_account_id, $access->supplier_id, $access->id);

        if (! $account) {
            return true;
        }

        $hasSupplierLink = CurrentAccountLink::query()
            ->where('tenant_account_id', $access->tenant_account_id)
            ->where('current_account_id', $account->id)
            ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
            ->where('link_id', $access->supplier_id)
            ->exists();

        $hasAccessLink = CurrentAccountLink::query()
            ->where('tenant_account_id', $access->tenant_account_id)
            ->where('current_account_id', $account->id)
            ->where('link_type', CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS)
            ->where('link_id', $access->id)
            ->exists();

        $hasCompanyLink = CurrentAccountLink::query()
            ->where('tenant_account_id', $access->tenant_account_id)
            ->where('current_account_id', $account->id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('is_primary', true)
            ->exists();

        return ! ($hasSupplierLink && $hasAccessLink && $hasCompanyLink);
    }
}
