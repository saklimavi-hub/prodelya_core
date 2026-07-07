<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountRole;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantSupplierAccessUsesExistingCariTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'current_accounts',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );
    }

    public function test_existing_customer_cari_is_reused_and_supplier_role_is_added_without_duplicate(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Mevcut Cari Tedarikçi',
            'code' => 'EXIST-SUP-001',
            'status' => 'active',
        ]);

        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Mevcut Cari Tedarikçi',
            'status' => 'active',
        ]);

        $company->companyRoles()->create([
            'tenant_account_id' => $this->tenant->id,
            'role_key' => 'customer',
        ]);

        $account = app(CurrentAccountSyncService::class)->ensureForCompany($company->fresh('companyRoles'));

        $beforeCompanyCount = Company::query()->where('tenant_account_id', $this->tenant->id)->count();
        $beforeAccountCount = CurrentAccount::query()->where('tenant_account_id', $this->tenant->id)->count();

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

        $company->refresh();
        $account->refresh();

        $this->assertSame($beforeCompanyCount, Company::query()->where('tenant_account_id', $this->tenant->id)->count());
        $this->assertSame($beforeAccountCount, CurrentAccount::query()->where('tenant_account_id', $this->tenant->id)->count());
        $this->assertTrue($company->fresh('companyRoles')->hasRole('customer'));
        $this->assertTrue($company->fresh('companyRoles')->hasRole('supplier'));
        $this->assertTrue($account->fresh('roles')->hasRole(CurrentAccountRole::ROLE_SUPPLIER));
        $this->assertSame(1, CurrentAccountLink::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('current_account_id', $account->id)
            ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
            ->where('link_id', $supplier->id)
            ->count());
    }
}
