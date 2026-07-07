<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierCariHotfixSensitiveTenantTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_supplier_hotfix_surfaces_do_not_render_sensitive_identifiers(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $owner = User::query()->create([
            'name' => 'Supplier Hotfix Sensitive Owner',
            'email' => 'supplier-hotfix-sensitive-owner@example.test',
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
            'name' => 'Hassas Kaynak Tedarikçisi',
            'code' => 'SENSITIVE-001',
            'status' => 'active',
            'config' => ['token' => 'hidden-token', 'file_path' => '/secrets/path'],
        ]);
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Hassas Cari',
            'phone' => '02121234567',
            'tax_number' => '1234567890',
            'tax_office' => 'Kartal',
            'status' => 'active',
        ]);
        $company->companyRoles()->create([
            'tenant_account_id' => $tenant->id,
            'role_key' => 'supplier',
        ]);

        $response = $this->actingAs($owner, 'web')
            ->get($this->tenantUrl($tenant, '/admin/companies/' . $company->id . '/edit'));

        $response->assertOk()
            ->assertDontSeeText('supplier_id')
            ->assertDontSeeText('tenant_id')
            ->assertDontSeeText('current_account_id')
            ->assertDontSeeText('source_type')
            ->assertDontSeeText('meta_json')
            ->assertDontSeeText('hidden-token')
            ->assertDontSeeText('/secrets/path');
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
