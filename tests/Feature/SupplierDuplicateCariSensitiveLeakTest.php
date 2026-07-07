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

class SupplierDuplicateCariSensitiveLeakTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_duplicate_repair_surfaces_do_not_render_technical_identifiers(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $owner = User::query()->create([
            'name' => 'Duplicate Sensitive Owner',
            'email' => 'duplicate-sensitive-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);
        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $owner->id,
            'role_id' => Role::query()->where('key', 'tenant_owner')->value('id'),
        ]);

        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $tenant->id, 'module_key' => 'current_accounts', 'feature_key' => null],
            ['is_enabled' => true]
        );

        $supplier = Supplier::query()->create([
            'name' => 'Leak Test Tedarikçi',
            'code' => 'LEAK-DUP-001',
            'status' => 'active',
            'config' => ['token' => 'hidden-token', 'file_path' => '/hidden/path'],
        ]);
        $access = TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        $canonical = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Leak Test Tedarikçi',
            'short_name' => 'Leak Ana',
            'status' => 'active',
        ]);
        $canonical->companyRoles()->create([
            'tenant_account_id' => $tenant->id,
            'role_key' => 'supplier',
        ]);
        $duplicate = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Leak Test Tedarikçi',
            'short_name' => 'Leak Duplicate',
            'status' => 'active',
        ]);
        $duplicate->companyRoles()->create([
            'tenant_account_id' => $tenant->id,
            'role_key' => 'supplier',
        ]);

        app(CurrentAccountSyncService::class)->ensureForCompany($canonical->fresh('companyRoles'));
        $duplicateAccount = app(CurrentAccountSyncService::class)->ensureForCompany($duplicate->fresh('companyRoles'));
        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $duplicateAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
            'is_primary' => true,
            'meta_json' => ['supplier_id' => $supplier->id, 'token' => 'hidden-token'],
        ]);
        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $duplicateAccount->id,
            'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
            'link_id' => $access->id,
            'is_primary' => false,
            'meta_json' => ['supplier_id' => $supplier->id],
        ]);

        app(TenantSupplierCurrentAccountSyncService::class)
            ->repairDuplicateSupplierCariLinks($tenant, $supplier, $canonical);

        $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/companies/' . $canonical->id . '/edit'))
            ->assertOk()
            ->assertDontSeeText('tenant_id')
            ->assertDontSeeText('current_account_id')
            ->assertDontSeeText('supplier_id')
            ->assertDontSeeText('source_type')
            ->assertDontSeeText('meta_json')
            ->assertDontSeeText('hidden-token')
            ->assertDontSeeText('/hidden/path');
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
