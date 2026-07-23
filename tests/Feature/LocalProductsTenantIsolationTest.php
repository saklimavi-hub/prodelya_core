<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class LocalProductsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_foreign_tenant_products_and_operational_stock_do_not_leak_into_current_tenant_lists(): void
    {
        $supplier = $this->makeSupplierWithAccess();
        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant',
            'legal_name' => 'Other Tenant A.S.',
            'slug' => 'other-tenant-local-products',
            'panel_subdomain' => 'other-tenant-local-products',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $foreignOwn = $this->makeCatalogProduct([
            'catalog_source' => 'local_product',
            'product_code' => 'FOREIGN-OWN-001',
            'product_name' => 'Foreign Own Product',
        ], $otherTenant);

        $foreignSupplierLocal = $this->makeCatalogProduct([
            'product_code' => 'FOREIGN-SUP-001',
            'product_name' => 'Foreign Supplier Local',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
        ], $otherTenant);
        $this->makeOperationalLocalStock($foreignSupplierLocal, 11, $otherTenant);

        $ownResponse = $this->getOnCentralHost('/admin/catalog/local-products');
        $supplierResponse = $this->getOnCentralHost('/admin/catalog/local-products/supplier-stock');

        $ownResponse->assertOk()->assertDontSeeText($foreignOwn->product_code);
        $supplierResponse->assertRedirect('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $catalogResponse = $this->getOnCentralHost('/admin/catalog?source_type=supplier&stock_state=local_stock');
        $catalogResponse->assertOk()->assertDontSeeText($foreignSupplierLocal->product_code);
    }
}
