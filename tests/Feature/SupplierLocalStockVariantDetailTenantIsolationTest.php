<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class SupplierLocalStockVariantDetailTenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_variant_detail_route_rejects_foreign_tenant_variant(): void
    {
        $supplier = $this->makeSupplierWithAccess('FOREIGN');
        $product = $this->makeCatalogProduct([
            'product_code' => 'HOME-001',
            'product_name' => 'Home Product',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
        ]);

        $foreignTenant = TenantAccount::query()->create([
            'company_name' => 'Foreign Tenant',
            'name' => 'Foreign Tenant',
            'slug' => 'foreign-tenant',
            'panel_subdomain' => 'foreign-' . uniqid(),
            'status' => 'active',
        ]);

        $foreignProduct = $this->makeCatalogProduct([
            'product_code' => 'FOREIGN-001',
            'product_name' => 'Foreign Product',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
        ], $foreignTenant);
        $foreignVariant = $this->makeCatalogVariant($foreignProduct, [
            'variant_code' => 'FOREIGN-V1',
            'variant_name' => 'Foreign Variant',
        ]);

        $response = $this->getOnCentralHost(route('admin.catalog.variants.show', ['product' => $product->id, 'variant' => $foreignVariant->id]));

        $response->assertForbidden();
    }
}
