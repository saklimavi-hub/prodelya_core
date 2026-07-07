<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccountLink;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantSupplierAccessDisableDoesNotDeleteCariTest extends TestCase
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

    public function test_disabling_supplier_access_keeps_existing_cari_and_links_for_history(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Kapanan Erişim Tedarikçisi',
            'code' => 'DISABLE-SUP-001',
            'status' => 'active',
        ]);

        $this->updateAccess($supplier, true);

        $companyId = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'Kapanan Erişim Tedarikçisi')
            ->value('id');

        $accountId = CurrentAccountLink::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('link_id', $companyId)
            ->value('current_account_id');

        $this->updateAccess($supplier, false);

        $this->assertDatabaseHas('tenant_supplier_access', [
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('companies', [
            'id' => $companyId,
            'tenant_account_id' => $this->tenant->id,
        ]);

        $this->assertDatabaseHas('current_accounts', [
            'id' => $accountId,
            'tenant_account_id' => $this->tenant->id,
        ]);

        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $accountId,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);
    }

    private function updateAccess(Supplier $supplier, bool $enabled): void
    {
        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.super.tenant-supplier-access.update', $this->tenant), [
                'supplier_access' => [
                    $supplier->id => [
                        'is_enabled' => $enabled ? '1' : '0',
                        'can_view_products' => $enabled ? '1' : '0',
                        'can_request_purchase' => $enabled ? '1' : '0',
                        'can_use_in_quotes' => $enabled ? '1' : '0',
                        'visible_in_catalog' => $enabled ? '1' : '0',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.super.tenant-supplier-access.edit', $this->tenant));
    }
}
