<?php

namespace Tests\Feature;

use App\Services\TenantCatalog\TenantCatalogProductSourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class TenantCatalogProductSourceResolverTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_it_classifies_own_projection_and_operational_supplier_rows_correctly(): void
    {
        $resolver = app(TenantCatalogProductSourceResolver::class);
        $supplier = $this->makeSupplierWithAccess();

        $own = $this->makeCatalogProduct([
            'catalog_source' => 'local_product',
            'product_code' => 'OWN-001',
            'product_name' => 'Own Product',
            'source_summary' => [],
        ]);

        $projectionOnly = $this->makeCatalogProduct([
            'product_code' => 'SUP-PROJECTION',
            'product_name' => 'Projection Only',
            'local_stock_quantity' => 1000,
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
        ]);

        $operational = $this->makeCatalogProduct([
            'product_code' => 'SUP-OPERATIONAL',
            'product_name' => 'Operational Supplier',
            'local_stock_quantity' => 0,
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
        ]);
        $this->makeOperationalLocalStock($operational, 7);

        $this->assertSame('own_product', $resolver->resolve($this->tenant, $own)['source_type']);
        $this->assertSame('supplier_catalog', $resolver->resolve($this->tenant, $projectionOnly)['source_type']);
        $this->assertSame('supplier_local_stock', $resolver->resolve($this->tenant, $operational)['source_type']);
    }
}
