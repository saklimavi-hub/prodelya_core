<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class SupplierRequestEditNoPriceLeakInPublicFormLinkTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_fiyatsiz_form_link_and_output_do_not_leak_price_fields(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-FORM');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-FORM-001');
        $requestRecord = $this->createSupplierRequest($procurement);

        $edit = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $edit->assertOk();
        $edit->assertSee(route('admin.procurements.supplier-requests.print', $requestRecord), false);

        $print = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.print', $requestRecord));

        $print->assertOk();
        $print->assertDontSee('purchase_price', false);
        $print->assertDontSee('supplier_cost', false);
    }
}
