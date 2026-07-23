<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class SupplierRequestEditButtonOrderTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_supplier_request_edit_renders_single_top_action_bar_with_ordered_buttons(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-EDIT');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-EDIT-001');
        $requestRecord = $this->createSupplierRequest($procurement)->fresh('items');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $response->assertOk();
        $response->assertSee('Tedarikçi Talebi ve Gelen Ürün Kaydı');
        $response->assertSee('Taslak Kaydet');
        $response->assertSee('Fiyatsız Talep Formu');
        $response->assertSee('Listeye Dön');
        $response->assertSee('Talebi Kaydet');
        $response->assertDontSee('Supplier request', false);
    }

    public function test_supplier_request_edit_keeps_priceless_print_link_and_no_double_header(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-PRINT');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-PRINT-001');
        $requestRecord = $this->createSupplierRequest($procurement);

        $edit = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $edit->assertOk();
        $edit->assertSeeInOrder(['Taslak Kaydet', 'Talebi Kaydet', 'Fiyatsız Talep Formu']);
        $edit->assertSeeText('Tedarikçi Talebi');

        $print = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.print', $requestRecord));

        $print->assertOk();
        $print->assertDontSee('Alış Liste Fiyatı');
        $print->assertDontSee('İskonto');
    }
}
