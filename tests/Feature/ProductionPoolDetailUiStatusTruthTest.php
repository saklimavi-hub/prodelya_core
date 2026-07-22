<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintProduction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsProductionShowFixtures;
use Tests\TestCase;

class ProductionPoolDetailUiStatusTruthTest extends TestCase
{
    use BuildsProductionShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::PRODUCTION_SHOW_HOST]);
        $this->setUpProductionShowFixtures();
    }

    public function test_pool_uses_single_full_width_summary_without_production_side_cards(): void
    {
        $this->createInternalProductionForShow();

        $response = $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions?route=internal'));

        $response->assertOk();
        $response->assertSee('pd-production-pool__summary-strip', false);
        $response->assertSee('Havuz Özeti');
        $response->assertDontSee('pd-production-summary', false);
        $response->assertDontSee('pd-layout-has-summary', false);
    }

    public function test_detail_uses_compact_status_truth_surface_and_new_next_action_label(): void
    {
        $production = $this->prepareProductionForReadyState($this->createInternalProductionForShow());

        $response = $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id));

        $response->assertOk();
        $response->assertSee('pd-ui-v1-production-detail', false);
        $response->assertSee('Üretim Detayı · Exact Baskı');
        $response->assertSee('Sıradaki İşlem');
        $response->assertSee('Operatör Ekranını Aç');
        $response->assertDontSee('Canonical Akış');
        $response->assertDontSee('prd-show-sidebar', false);
        $response->assertDontSee('pd-layout-has-summary', false);
    }

    public function test_completed_production_never_renders_waiting_in_production_status(): void
    {
        $production = $this->prepareProductionForReadyState($this->createInternalProductionForShow([
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'completed_quantity' => 100,
            'remaining_quantity' => 0,
            'completed_at' => now(),
        ]));

        $response = $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id));

        $response->assertOk();
        $response->assertSee('Tamamlandı');
        $response->assertSee('%100');
        $response->assertDontSee('Üretimde — Bekliyor');
        $response->assertDontSee('Üretimde - Bekliyor');
    }

    public function test_disabled_or_not_required_qc_is_not_rendered_as_waiting(): void
    {
        $production = $this->prepareProductionForReadyState($this->createInternalProductionForShow([
            'qc_status' => null,
            'qc_started_at' => null,
        ]));

        $response = $this->actingAs($this->adminUser, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id));

        $response->assertOk();
        $response->assertSee('Kalite kontrol gerekli değil');
        $response->assertDontSee('Kalite Kontrol</span>\n <strong>Bekliyor</strong>', false);
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . self::PRODUCTION_SHOW_HOST . $path;
    }
}
