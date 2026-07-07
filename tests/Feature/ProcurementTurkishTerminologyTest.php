<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementTurkishTerminologyTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_procurement_surfaces_use_turkish_labels_and_hide_technical_terms(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-TR');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-TR-001');
        $requestRecord = $this->createSupplierRequest($procurement);

        $index = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.index'));

        $index->assertOk();
        $index->assertSee('Tedarikçi');
        $index->assertSee('Ürün');
        $index->assertSee('Kısmi Geldi');
        $index->assertSee('Tamamı Geldi');
        $index->assertDontSee('Tedarikci');
        $index->assertDontSee('Urun');

        $edit = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $requestRecord));

        $edit->assertOk();
        $edit->assertSee('Fiyatsız Talep Formunu Aç');
        $edit->assertSee('Tedarik Listesine Dön');
        $edit->assertDontSee('Supplier request', false);
        $edit->assertDontSee('group_code', false);
    }
}
