<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class SupplierRequestPriceFreePrintReferenceTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_price_free_print_reference_renders_expected_fields_without_sensitive_financial_data(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-PRINT');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-PRINT-001');
        $requestRecord = $this->createSupplierRequest($procurement);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.print', $requestRecord));

        $response->assertOk();
        $response->assertSee('data-procurement-reference-family="price-free-print"', false);
        $response->assertSee('TEDARİKÇİ TALEP FORMU');
        $response->assertSee('Yazdır');
        $response->assertSee($requestRecord->request_number);
        $response->assertSee($procurement->order->document_number);
        $response->assertSee((string) data_get($procurement->snapshot, 'product_code'));
        $response->assertSee((string) data_get($procurement->snapshot, 'product_name'));
        $response->assertSee('Tedarikçi Yetkilisi');
        $response->assertSee('Firma Yetkilisi');
        $response->assertDontSee('Alış Liste');
        $response->assertDontSee('Alış Toplam');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_mapping', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('price_snapshot', false);
    }
}
