<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementIndexUiV1ScopedTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_index_uses_scoped_procurement_ui_v1_wrapper_and_css_contract(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-UIV1');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-UIV1-001');
        $requestRecord = $this->createSupplierRequest($procurement);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.index'));

        $response->assertOk();
        $response->assertSee('Tedarik / Malzeme İşleri');
        $response->assertSee('Yeni Tedarik Talep Ailesi');
        $response->assertSee('data-procurement-reference-family="request-list"', false);
        $response->assertSee('pd-ui-v1-procurement', false);
        $response->assertSee('Bekleyenler / Açık Talepler');
        $response->assertSee('Kısmi Gelenler');
        $response->assertSee('İptal Edilenler');
        $response->assertSee($requestRecord->request_number);
        $response->assertDontSee('<style>', false);

        $css = file_get_contents(public_path('css/prodelya-admin.css'));
        $this->assertStringContainsString('/* UI-V1 PILOT — PROCUREMENT INDEX ONLY */', $css);
        $this->assertStringContainsString('.pd-ui-v1-procurement', $css);
        $this->assertStringContainsString('.pd-ui-v1-procurement__layout', $css);
        $this->assertStringContainsString('@media (max-width: 1180px)', $css);
        $this->assertStringContainsString('.pd-ui-v1-procurement__primary-action', $css);
    }

    public function test_index_shows_exact_sku_requested_received_remaining_and_one_primary_action_text_per_row_family(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-UIV1-B');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-UIV1-002', [
            'product_name' => 'Exact Varyant Ürün',
            'product_code' => 'EXACT-SKU-002',
        ]);
        $requestRecord = $this->createSupplierRequest($procurement);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.index', ['tab' => 'pending', 'supplier_id' => $supplier->id]));

        $response->assertOk();
        $response->assertSee('Talep / Kaynak');
        $response->assertSee('Ürün / Exact SKU');
        $response->assertSee('Miktar');
        $response->assertSee('İstenen');
        $response->assertSee('Gelen');
        $response->assertSee('Kalan');
        $response->assertSee('Exact Varyant Ürün');
        $response->assertSee('EXACT-SKU-002');
        $response->assertSee('Talebi Aç');
        $response->assertSee('Talep Hazırla');
        $response->assertSee('Talebi Düzenle');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_mapping', false);
        $response->assertDontSee('unit_price', false);
    }
}
