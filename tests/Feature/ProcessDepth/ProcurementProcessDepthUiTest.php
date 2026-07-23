<?php

namespace Tests\Feature\ProcessDepth;

use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementProcessDepthUiTest extends TestCase
{
    use RefreshDatabase;


    use InteractsWithProcurementFixtures;
    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_procurement_detail_changes_visible_surfaces_by_process_depth(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-DEPTH');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-DEPTH-001');
        $this->createSupplierRequest($procurement);

        TenantSetting::setValue($this->tenant->id, 'process_depth', 'fast', 'string');
        $fast = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', $procurement));
        $fast->assertOk();
        $fast->assertSee('data-procurement-depth="fast"', false);
        $fast->assertDontSee('Kısa Faaliyet Geçmişi');
        $fast->assertDontSee('Kontrol Özeti');

        TenantSetting::setValue($this->tenant->id, 'process_depth', 'standard', 'string');
        $standard = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', $procurement));
        $standard->assertOk();
        $standard->assertSee('data-procurement-depth="standard"', false);
        $standard->assertSee('Kısa Faaliyet Geçmişi');
        $standard->assertDontSee('Kontrol Özeti');

        TenantSetting::setValue($this->tenant->id, 'process_depth', 'controlled', 'string');
        $controlled = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', $procurement));
        $controlled->assertOk();
        $controlled->assertSee('data-procurement-depth="controlled"', false);
        $controlled->assertSee('Kısa Faaliyet Geçmişi');
        $controlled->assertSee('Kontrol Özeti');
    }
}
