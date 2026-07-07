<?php

namespace Tests\Feature;

use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountRole;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantSupplierAccessCreatesSupplierCariTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private TenantAccount $tenant;
    private User $tenantOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenantOwner = User::query()->create([
            'name' => 'Supplier Cari Owner',
            'email' => 'supplier-cari-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->tenantOwner->id,
            'role_id' => Role::query()->where('key', 'tenant_owner')->value('id'),
        ]);

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'current_accounts',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );
    }

    public function test_supplier_access_update_creates_supplier_cari_and_current_account_links(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Otomatik Tedarikçi Cari',
            'code' => 'AUTO-SUP-001',
            'status' => 'active',
            'contact_email' => 'tedarikci@example.test',
            'contact_phone' => '02125550000',
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.super.tenant-supplier-access.update', $this->tenant), [
                'supplier_access' => [
                    $supplier->id => [
                        'is_enabled' => '1',
                        'can_view_products' => '1',
                        'can_request_purchase' => '1',
                        'can_use_in_quotes' => '1',
                        'visible_in_catalog' => '1',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.super.tenant-supplier-access.edit', $this->tenant));

        $this->assertDatabaseHas('companies', [
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Otomatik Tedarikçi Cari',
            'status' => 'active',
        ]);

        $companyId = \App\Models\Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'Otomatik Tedarikçi Cari')
            ->value('id');

        $this->assertDatabaseHas('company_roles', [
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $companyId,
            'role_key' => 'supplier',
        ]);

        $companyLink = CurrentAccountLink::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('link_id', $companyId)
            ->where('is_primary', true)
            ->firstOrFail();

        $this->assertDatabaseHas('current_account_roles', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $companyLink->current_account_id,
            'role' => CurrentAccountRole::ROLE_SUPPLIER,
        ]);

        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $companyLink->current_account_id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);

        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $companyLink->current_account_id,
            'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
        ]);

        $this->actingAs($this->tenantOwner, 'web')
            ->get($this->tenantUrl('/admin/companies'))
            ->assertOk()
            ->assertSee('Otomatik Tedarikçi Cari')
            ->assertSee('Tedarikçi');
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
