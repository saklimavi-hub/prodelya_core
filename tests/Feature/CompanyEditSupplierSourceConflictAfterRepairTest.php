<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccountLink;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use App\Services\TenantSupplierCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyEditSupplierSourceConflictAfterRepairTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_canonical_company_can_save_after_repair_and_real_conflict_still_blocks(): void
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Conflict Repair Tenant',
            'legal_name' => 'Conflict Repair Tenant Ltd. Şti.',
            'slug' => 'conflict-repair-tenant',
            'panel_subdomain' => 'conflict-repair-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
        $owner = $this->makeOwner($tenant);

        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $tenant->id, 'module_key' => 'current_accounts', 'feature_key' => null],
            ['is_enabled' => true]
        );

        $supplier = Supplier::query()->create([
            'name' => 'Conflict Sonrası Tedarikçi',
            'code' => 'POST-REPAIR-CONFLICT-001',
            'status' => 'active',
        ]);
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        $canonical = $this->createSupplierCompany($tenant, 'Conflict Sonrası Tedarikçi', 'Ana Cari', [
            'phone' => '02121234567',
            'tax_number' => '1234567890',
            'tax_office' => 'Şişli',
        ]);
        $duplicate = $this->createSupplierCompany($tenant, 'Conflict Sonrası Tedarikçi', 'Duplicate Cari', [
            'phone' => '02121234567',
            'tax_number' => '1234567891',
            'tax_office' => 'Şişli',
        ]);
        $other = $this->createSupplierCompany($tenant, 'Başka Cari', 'Gerçek Çakışma', [
            'phone' => '02121234567',
            'tax_number' => '1234567892',
            'tax_office' => 'Şişli',
        ]);

        $canonicalAccount = app(CurrentAccountSyncService::class)->ensureForCompany($canonical->fresh('companyRoles'));
        $duplicateAccount = app(CurrentAccountSyncService::class)->ensureForCompany($duplicate->fresh('companyRoles'));
        app(CurrentAccountSyncService::class)->ensureForCompany($other->fresh('companyRoles'));

        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $duplicateAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
            'is_primary' => true,
            'meta_json' => ['linked_via' => 'test'],
        ]);

        app(TenantSupplierCurrentAccountSyncService::class)
            ->repairDuplicateSupplierCariLinks($tenant, $supplier, $canonical);

        $this->actingAs($owner, 'web')
            ->from($this->tenantUrl($tenant, '/admin/companies/' . $canonical->id . '/edit'))
            ->put($this->tenantUrl($tenant, '/admin/companies/' . $canonical->id), [
                'identity_type' => 'company',
                'legal_name' => $canonical->legal_name,
                'short_name' => $canonical->short_name,
                'phone' => $canonical->phone,
                'tax_number' => $canonical->tax_number,
                'tax_office' => $canonical->tax_office,
                'status' => 'active',
                'roles' => ['supplier'],
                'supplier_id' => $supplier->id,
            ])
            ->assertRedirect($this->tenantUrl($tenant, '/admin/companies/' . $canonical->id));

        CurrentAccountLink::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'link_type' => CurrentAccountLink::LINK_SUPPLIER,
                'link_id' => $supplier->id,
            ],
            [
                'current_account_id' => $canonicalAccount->id,
                'is_primary' => true,
                'meta_json' => ['linked_via' => 'test'],
            ]
        );

        $this->actingAs($owner, 'web')
            ->from($this->tenantUrl($tenant, '/admin/companies/' . $other->id . '/edit'))
            ->followingRedirects()
            ->put($this->tenantUrl($tenant, '/admin/companies/' . $other->id), [
                'identity_type' => 'company',
                'legal_name' => $other->legal_name,
                'short_name' => $other->short_name,
                'phone' => $other->phone,
                'tax_number' => $other->tax_number,
                'tax_office' => $other->tax_office,
                'status' => 'active',
                'roles' => ['supplier'],
                'supplier_id' => $supplier->id,
            ])
            ->assertOk()
            ->assertSee('Bu hazır ürün kaynağı şu Cari Kart ile eşleştirilmiş: ' . $canonical->short_name . '.');
    }

    private function makeOwner(TenantAccount $tenant): User
    {
        $owner = User::query()->create([
            'name' => 'Conflict After Repair Owner',
            'email' => 'conflict-after-repair-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);
        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $owner->id,
            'role_id' => Role::query()->where('key', 'tenant_owner')->value('id'),
        ]);

        return $owner;
    }

    private function createSupplierCompany(TenantAccount $tenant, string $legalName, string $shortName, array $overrides = []): Company
    {
        $company = Company::query()->create(array_merge([
            'tenant_account_id' => $tenant->id,
            'legal_name' => $legalName,
            'short_name' => $shortName,
            'status' => 'active',
        ], $overrides));
        $company->companyRoles()->create([
            'tenant_account_id' => $tenant->id,
            'role_key' => 'supplier',
        ]);

        return $company;
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
