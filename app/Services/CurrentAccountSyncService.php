<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountRole;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CurrentAccountSyncService
{
    public function ensureForCompany(Company $company): CurrentAccount
    {
        return DB::transaction(function () use ($company): CurrentAccount {
            $company->loadMissing('companyRoles');

            $existingLink = CurrentAccountLink::query()
                ->where('tenant_account_id', $company->tenant_account_id)
                ->where('link_type', CurrentAccountLink::LINK_COMPANY)
                ->where('link_id', $company->id)
                ->first();

            if ($existingLink) {
                $account = CurrentAccount::query()
                    ->where('tenant_account_id', $company->tenant_account_id)
                    ->findOrFail($existingLink->current_account_id);

                $account->fill($this->companyPayload($company));
                $account->save();

                $this->linkCompany($account, $company);
                $this->syncCompanyRoles($account, $company);

                return $account->fresh(['roles', 'links']);
            }

            $account = CurrentAccount::query()->create(array_merge(
                ['tenant_account_id' => $company->tenant_account_id],
                $this->companyPayload($company)
            ));

            $this->linkCompany($account, $company);
            $this->syncCompanyRoles($account, $company);

            return $account->fresh(['roles', 'links']);
        });
    }

    public function ensureRole(CurrentAccount $account, string $role): CurrentAccountRole
    {
        $this->guardRole($role);

        return CurrentAccountRole::query()->firstOrCreate(
            [
                'tenant_account_id' => $account->tenant_account_id,
                'current_account_id' => $account->id,
                'role' => $role,
            ],
            [
                'is_primary' => false,
                'status' => CurrentAccountRole::STATUS_ACTIVE,
            ]
        );
    }

    public function linkCompany(CurrentAccount $account, Company $company): CurrentAccountLink
    {
        $this->guardSameTenant($account->tenant_account_id, $company->tenant_account_id);

        return CurrentAccountLink::query()->updateOrCreate(
            [
                'tenant_account_id' => $account->tenant_account_id,
                'link_type' => CurrentAccountLink::LINK_COMPANY,
                'link_id' => $company->id,
            ],
            [
                'current_account_id' => $account->id,
                'is_primary' => true,
                'meta_json' => [
                    'company_role_keys' => $company->getRoleKeys(),
                ],
            ]
        );
    }

    public function ensureForSupplier(Supplier $supplier, TenantAccount $tenant): CurrentAccount
    {
        return DB::transaction(function () use ($supplier, $tenant): CurrentAccount {
            $existingLink = CurrentAccountLink::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
                ->where('link_id', $supplier->id)
                ->first();

            if ($existingLink) {
                return CurrentAccount::query()
                    ->where('tenant_account_id', $tenant->id)
                    ->findOrFail($existingLink->current_account_id)
                    ->fresh(['roles', 'links']);
            }

            $account = CurrentAccount::query()->create([
                'tenant_account_id' => $tenant->id,
                'display_name' => trim((string) ($supplier->name ?: ('Tedarikçi #' . $supplier->id))),
                'legal_name' => $supplier->name,
                'email' => $supplier->contact_email,
                'phone' => $supplier->contact_phone,
                'website' => $supplier->website,
                'status' => $supplier->status === 'active' ? CurrentAccount::STATUS_ACTIVE : CurrentAccount::STATUS_PASSIVE,
                'notes' => 'Global supplier kaydından türetildi.',
            ]);

            CurrentAccountLink::query()->create([
                'tenant_account_id' => $tenant->id,
                'current_account_id' => $account->id,
                'link_type' => CurrentAccountLink::LINK_SUPPLIER,
                'link_id' => $supplier->id,
                'is_primary' => true,
                'meta_json' => [
                    'supplier_code' => $supplier->code,
                ],
            ]);

            $this->linkTenantSupplierAccessIfExists($account, $supplier, $tenant);
            $this->ensureRole($account, CurrentAccountRole::ROLE_SUPPLIER);

            return $account->fresh(['roles', 'links']);
        });
    }

    public function syncCompanyRoles(CurrentAccount $account, Company $company): void
    {
        $this->guardSameTenant($account->tenant_account_id, $company->tenant_account_id);

        $company->loadMissing('companyRoles');

        $mappedRoles = $company->companyRoles
            ->map(fn ($companyRole) => $this->mapCompanyRoleToCurrentAccountRole($companyRole->role_key))
            ->filter()
            ->unique()
            ->values();

        CurrentAccountRole::query()
            ->where('tenant_account_id', $account->tenant_account_id)
            ->where('current_account_id', $account->id)
            ->whereIn('role', $this->companyManagedCurrentAccountRoles())
            ->when(
                $mappedRoles->isNotEmpty(),
                fn ($query) => $query->whereNotIn('role', $mappedRoles->all())
            )
            ->delete();

        foreach ($mappedRoles as $mappedRole) {
            $this->ensureRole($account, $mappedRole);
        }
    }

    public function linkTenantSupplierAccessIfExists(CurrentAccount $account, Supplier $supplier, TenantAccount $tenant): void
    {
        $access = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('supplier_id', $supplier->id)
            ->first();

        if (!$access) {
            return;
        }

        CurrentAccountLink::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
                'link_id' => $access->id,
            ],
            [
                'current_account_id' => $account->id,
                'is_primary' => false,
                'meta_json' => [
                    'supplier_id' => $supplier->id,
                    'is_active' => (bool) $access->is_active,
                    'can_request_purchase' => (bool) $access->can_request_purchase,
                ],
            ]
        );
    }

    public function syncRoles(CurrentAccount $account, array $roles): void
    {
        $normalizedRoles = collect($roles)
            ->filter(fn ($role) => is_string($role) && $role !== '')
            ->map(fn ($role) => trim($role))
            ->unique()
            ->values();

        foreach ($normalizedRoles as $role) {
            $this->guardRole($role);
        }

        $roleCleanupQuery = CurrentAccountRole::query()
            ->where('tenant_account_id', $account->tenant_account_id)
            ->where('current_account_id', $account->id);

        if ($normalizedRoles->isEmpty()) {
            $roleCleanupQuery->delete();
        } else {
            $roleCleanupQuery->whereNotIn('role', $normalizedRoles->all())->delete();
        }

        foreach ($normalizedRoles as $index => $role) {
            CurrentAccountRole::query()->updateOrCreate(
                [
                    'tenant_account_id' => $account->tenant_account_id,
                    'current_account_id' => $account->id,
                    'role' => $role,
                ],
                [
                    'is_primary' => $index === 0,
                    'status' => CurrentAccountRole::STATUS_ACTIVE,
                ]
            );
        }
    }

    public function ensureCompanyForCurrentAccount(CurrentAccount $account, array $roles): ?Company
    {
        $companyLink = CurrentAccountLink::query()
            ->where('tenant_account_id', $account->tenant_account_id)
            ->where('current_account_id', $account->id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('is_primary', true)
            ->first();

        $company = null;

        if ($companyLink) {
            $company = Company::query()
                ->where('tenant_account_id', $account->tenant_account_id)
                ->find($companyLink->link_id);
        }

        if (!$company && !$this->requiresCompanyLink($roles)) {
            return null;
        }

        if (!$company) {
            $company = Company::query()->create([
                'tenant_account_id' => $account->tenant_account_id,
                'legal_name' => $account->legal_name ?: $account->display_name,
                'short_name' => $account->short_name,
                'tax_office' => $account->tax_office,
                'tax_number' => $account->tax_number,
                'email' => $account->email,
                'phone' => $account->phone,
                'mobile' => $account->mobile,
                'website' => $account->website,
                'status' => $this->normalizeCurrentAccountStatusForCompany($account->status),
                'risk_status' => $account->risk_status,
                'portal_enabled' => false,
                'notes' => $this->appendCurrentAccountNote($account),
            ]);
        } else {
            $company->fill([
                'legal_name' => $account->legal_name ?: $account->display_name,
                'short_name' => $account->short_name,
                'tax_office' => $account->tax_office,
                'tax_number' => $account->tax_number,
                'email' => $account->email,
                'phone' => $account->phone,
                'mobile' => $account->mobile,
                'website' => $account->website,
                'status' => $this->normalizeCurrentAccountStatusForCompany($account->status),
                'risk_status' => $account->risk_status,
                'notes' => $this->appendCurrentAccountNote($account),
            ]);
            $company->save();
        }

        $this->linkCompany($account, $company);
        $this->syncCurrentAccountRolesToCompany($account, $company, $roles);

        return $company->fresh('companyRoles');
    }

    private function companyPayload(Company $company): array
    {
        $displayName = trim((string) ($company->short_name ?: $company->legal_name ?: ('Firma #' . $company->id)));

        return [
            'display_name' => $displayName,
            'legal_name' => $company->legal_name,
            'short_name' => $company->short_name,
            'tax_office' => $company->tax_office,
            'tax_number' => $company->tax_number,
            'email' => $company->email,
            'phone' => $company->phone,
            'mobile' => $company->mobile,
            'website' => $company->website,
            'risk_status' => $company->risk_status,
            'status' => $this->normalizeCompanyStatus($company->status),
            'notes' => $company->notes,
        ];
    }

    private function normalizeCompanyStatus(?string $status): string
    {
        return match ((string) $status) {
            'active' => CurrentAccount::STATUS_ACTIVE,
            'inactive', 'passive' => CurrentAccount::STATUS_PASSIVE,
            'blocked' => CurrentAccount::STATUS_BLOCKED,
            default => CurrentAccount::STATUS_ACTIVE,
        };
    }

    private function normalizeCurrentAccountStatusForCompany(?string $status): string
    {
        return match ((string) $status) {
            CurrentAccount::STATUS_ACTIVE => 'active',
            CurrentAccount::STATUS_BLOCKED => 'blocked',
            CurrentAccount::STATUS_PASSIVE,
            CurrentAccount::STATUS_ARCHIVED => 'inactive',
            default => 'active',
        };
    }

    private function syncCurrentAccountRolesToCompany(CurrentAccount $account, Company $company, array $roles): void
    {
        $this->guardSameTenant($account->tenant_account_id, $company->tenant_account_id);

        $mappedRoleKeys = collect($roles)
            ->map(fn ($role) => $this->mapCurrentAccountRoleToCompanyRole($role))
            ->filter()
            ->unique()
            ->values();

        $companyRoleCleanupQuery = $company->companyRoles();

        if ($mappedRoleKeys->isEmpty()) {
            $companyRoleCleanupQuery->delete();
        } else {
            $companyRoleCleanupQuery->whereNotIn('role_key', $mappedRoleKeys->all())->delete();
        }

        foreach ($mappedRoleKeys as $roleKey) {
            $company->companyRoles()->updateOrCreate(
                ['role_key' => $roleKey],
                ['tenant_account_id' => $company->tenant_account_id]
            );
        }
    }

    private function mapCurrentAccountRoleToCompanyRole(string $role): ?string
    {
        return match ($role) {
            CurrentAccountRole::ROLE_CUSTOMER => 'customer',
            CurrentAccountRole::ROLE_SUPPLIER => 'supplier',
            CurrentAccountRole::ROLE_SUBCONTRACTOR => 'print_fason',
            CurrentAccountRole::ROLE_SERVICE_PROVIDER => 'production_partner',
            CurrentAccountRole::ROLE_CARRIER => 'delivery_partner',
            CurrentAccountRole::ROLE_OTHER => 'other',
            default => null,
        };
    }

    private function mapCompanyRoleToCurrentAccountRole(string $roleKey): ?string
    {
        return match ($roleKey) {
            'customer' => CurrentAccountRole::ROLE_CUSTOMER,
            'supplier' => CurrentAccountRole::ROLE_SUPPLIER,
            'print_fason' => CurrentAccountRole::ROLE_SUBCONTRACTOR,
            'production_partner' => CurrentAccountRole::ROLE_SERVICE_PROVIDER,
            'delivery_partner' => CurrentAccountRole::ROLE_CARRIER,
            'other' => CurrentAccountRole::ROLE_OTHER,
            default => null,
        };
    }

    private function companyManagedCurrentAccountRoles(): array
    {
        return [
            CurrentAccountRole::ROLE_CUSTOMER,
            CurrentAccountRole::ROLE_SUPPLIER,
            CurrentAccountRole::ROLE_SUBCONTRACTOR,
            CurrentAccountRole::ROLE_SERVICE_PROVIDER,
            CurrentAccountRole::ROLE_CARRIER,
            CurrentAccountRole::ROLE_OTHER,
        ];
    }

    private function requiresCompanyLink(array $roles): bool
    {
        return collect($roles)->contains(fn ($role) => in_array($role, [
            CurrentAccountRole::ROLE_CUSTOMER,
            CurrentAccountRole::ROLE_SUBCONTRACTOR,
            CurrentAccountRole::ROLE_CARRIER,
            CurrentAccountRole::ROLE_SERVICE_PROVIDER,
        ], true));
    }

    private function appendCurrentAccountNote(CurrentAccount $account): ?string
    {
        $prefix = 'Current account UI kaydından senkronlandı.';

        if (!filled($account->notes)) {
            return $prefix;
        }

        if (Str::contains($account->notes, $prefix)) {
            return $account->notes;
        }

        return trim($account->notes . PHP_EOL . PHP_EOL . $prefix);
    }

    private function guardRole(string $role): void
    {
        if (!array_key_exists($role, CurrentAccountRole::roleLabels())) {
            throw new InvalidArgumentException('Geçersiz cari rolü.');
        }
    }

    private function guardSameTenant(int $accountTenantId, int $entityTenantId): void
    {
        if ($accountTenantId !== $entityTenantId) {
            throw new InvalidArgumentException('Farklı tenant kayıtları aynı cari kimliğine bağlanamaz.');
        }
    }
}
