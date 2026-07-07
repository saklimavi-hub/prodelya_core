<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Models\User;
use App\Services\TenantSupplierCurrentAccountSyncService;
use App\Models\Supplier;
use App\Models\TenantSupplierAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyShowSupplierMatchingTabTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_supplier_company_show_displays_supplier_matching_tab_without_technical_fields(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $supplier = Supplier::query()->create([
            'name' => 'İlpen',
            'code' => 'ILPEN-D1',
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        $result = app(TenantSupplierCurrentAccountSyncService::class)->syncForTenantSupplierAccess($tenant, $supplier);
        $company = $result['company'];

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.companies.show', ['company' => $company, 'tab' => 'tedarikci']));

        $response->assertOk()
            ->assertSee('Tedarikçi Eşleşme')
            ->assertSee('Hazır Ürün Kaynağı')
            ->assertSee('İlpen')
            ->assertSee('Tedarik ekranında kullanılabilir')
            ->assertDontSee('supplier_id')
            ->assertDontSee('tenant_id')
            ->assertDontSee('current_account_id')
            ->assertDontSee('source_type');
    }
}
