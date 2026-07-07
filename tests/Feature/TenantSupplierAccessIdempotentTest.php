<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSupplierAccess;
use App\Services\TenantSupplierCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantSupplierAccessIdempotentTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_sync_service_is_idempotent_for_same_supplier_access(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'İdempotent Tedarikçi',
            'code' => 'IDEMP-SUP-001',
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        $service = app(TenantSupplierCurrentAccountSyncService::class);

        $service->syncForTenantSupplierAccess($this->tenant, $supplier);
        $service->syncForTenantSupplierAccess($this->tenant, $supplier);

        $this->assertSame(1, Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'İdempotent Tedarikçi')
            ->count());

        $this->assertSame(1, CurrentAccountLink::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
            ->where('link_id', $supplier->id)
            ->count());

        $accountId = CurrentAccountLink::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
            ->where('link_id', $supplier->id)
            ->value('current_account_id');

        $this->assertNotNull($accountId);
        $this->assertSame(1, CurrentAccount::query()->where('tenant_account_id', $this->tenant->id)->where('id', $accountId)->count());
        $this->assertSame(1, CurrentAccountLink::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('current_account_id', $accountId)
            ->where('link_type', CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS)
            ->count());
    }
}
