<?php

namespace Tests\Feature;

use App\Models\OrderItemProcurement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementShowTabbedLayoutTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_procurement_show_uses_tabbed_layout_and_invalid_tab_falls_back_to_genel(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-TAB');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-TAB-001');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', ['procurement' => $procurement, 'tab' => 'bilinmeyen']));

        $response->assertOk();
        $response->assertSee('Tedarik Sekmeleri');
        $response->assertSee('Genel Özet');
        $response->assertSee('Ürün ve Sipariş');
        $response->assertSee('Tedarikçi ve Cari');
        $response->assertSee('Talep / Form');
        $response->assertSee('İşlemler');
        $response->assertSee('Gelen / Miktar');
        $response->assertSee('Geçmiş');
        $response->assertSee('?tab=genel', false);
        $response->assertDontSee('A) Sipariş ve Ürün Özeti');
        $response->assertDontSee('Tedarik Özeti');
    }

    public function test_procurement_show_displays_status_based_primary_actions(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-ACT');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-ACT-001');

        $pending = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', ['procurement' => $procurement, 'tab' => 'islemler']));

        $pending->assertSee('Talep Aç');
        $pending->assertDontSee('Sipariş Verildi</button>', false);

        $procurement->update(['procurement_status' => OrderItemProcurement::STATUS_REQUEST_CREATED]);

        $requested = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', ['procurement' => $procurement->fresh(), 'tab' => 'islemler']));

        $requested->assertSee('Sipariş Verildi');
        $requested->assertDontSee('Bu tedarik kaydı tamamlandı.');

        $procurement->update(['procurement_status' => OrderItemProcurement::STATUS_SUPPLIER_ORDERED]);

        $ordered = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', ['procurement' => $procurement->fresh(), 'tab' => 'islemler']));

        $ordered->assertSee('Kısmi Geldi');
        $ordered->assertSee('Tamamı Geldi');
    }
}
