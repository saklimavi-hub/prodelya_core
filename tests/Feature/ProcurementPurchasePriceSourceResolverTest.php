<?php

namespace Tests\Feature;

use App\Models\SupplierProductRaw;
use App\Services\Procurement\SupplierPurchasePriceSourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementPurchasePriceSourceResolverTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_procurement_resolver_uses_supplier_purchase_truth_and_ignores_sales_snapshot(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-SRC');

        $raw = SupplierProductRaw::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'SRC-' . uniqid(),
            'source_name' => 'Fixture Kaynak Ürün',
            'supplier_product_code' => 'RAW-PROC-SRC-001',
            'product_name' => 'Resolver Ürün',
            'purchase_price' => '7.7500',
            'currency' => 'USD',
            'source_price' => '9.9900',
            'source_currency' => 'USD',
            'sync_status' => 'processed',
        ]);

        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-SRC-001', [
            'product_snapshot' => [
                'product_name' => 'Resolver Ürün',
                'product_code' => 'PROC-SRC-001',
                'supplier_product_raw_id' => $raw->id,
            ],
            'price_snapshot' => [
                'source_price' => 10.00,
                'source_currency' => 'USD',
                'list_price' => 10.00,
                'document_currency' => 'TRY',
            ],
            'list_price' => 10.00,
            'unit_price' => 10.00,
            'line_total' => 600.00,
        ]);

        $resolved = app(SupplierPurchasePriceSourceResolver::class)->resolveForProcurement($procurement->fresh(['orderItem']));

        $this->assertSame('resolved', $resolved['resolution_status']);
        $this->assertSame('USD', $resolved['currency_original']);
        $this->assertSame(7.75, (float) $resolved['amount_original']);
        $this->assertNotSame(10.0, (float) $resolved['amount_original']);
        $this->assertSame('supplier_list_price', $resolved['source_kind']);
    }
}
