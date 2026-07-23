<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementNewReferenceFamilyTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_index_and_detail_render_new_procurement_reference_family_markers(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-REF');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-REF-001');
        $requestRecord = $this->createSupplierRequest($procurement);

        $index = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.index'));

        $index->assertOk();
        $index->assertSee('data-procurement-reference-family="request-list"', false);
        $index->assertSee('Açık Talepler');
        $index->assertSee('Sipariş Verilenler');
        $index->assertSee('Tamamlananlar');
        $index->assertSee('Talebi Aç');
        $index->assertSee($requestRecord->request_number);

        $detail = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', $procurement));

        $detail->assertOk();
        $detail->assertSee('data-procurement-reference-family="detail"', false);
        $detail->assertSee('Üst Sıradaki İş');
        $detail->assertSee('Üç Aşamalı Süreç');
        $detail->assertSee('Sağ Kısa Özet');
        $detail->assertSee(route('admin.procurements.supplier-requests.edit', $requestRecord), false);
    }
}
