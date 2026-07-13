<?php

namespace Tests\Feature\ProcessDepth;

use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormActivityLog;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicProcessDepthUiTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->setUpGraphicShowFixtures();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];

        parent::tearDown();
    }

    public function test_fast_depth_renders_compact_graphic_focus_without_controlled_details(): void
    {
        [$workForm, $graphic] = $this->prepareGraphicDepthFixture('GRAPHIC-DEPTH-FAST-001');
        TenantSetting::setValue($workForm->tenant_account_id, 'process_depth', 'fast', 'string');

        $response = $this->showGraphic($workForm, $graphic);
        $content = $response->getContent();

        $response->assertOk()
            ->assertSee('Hızlı Akış')
            ->assertSee('data-graphic-process-depth="fast"', false)
            ->assertSee('data-graphic-depth-branch="fast"', false)
            ->assertSee('data-graphic-depth-marker="true"', false)
            ->assertSee('data-sticky-layout="true"', false)
            ->assertSee('data-sticky-sidebar="true"', false)
            ->assertSee('graphic-depth-fast-panel', false)
            ->assertSee('graphic-main-preview-frame', false)
            ->assertSee('Aktif Odak')
            ->assertDontSee('graphic-operation-tabs', false)
            ->assertDontSee('graphic-action-step-tabs', false)
            ->assertDontSee('graphic-history-block', false)
            ->assertDontSee('graphic-controlled-attachment-block', false)
            ->assertDontSee('graphic-readiness-sidebar', false)
            ->assertDontSee('graphic-recent-activities-sidebar', false)
            ->assertDontSee('physical_path', false)
            ->assertDontSee('grand_total', false)
            ->assertDontSee('KDV');

        $this->assertSame(1, substr_count($content, 'data-canonical-focus-panel="true"'));
        $this->assertSame(1, substr_count($content, 'pd-graphic-depth-primary-cta'));
    }

    public function test_standard_depth_keeps_balanced_surface_without_controlled_only_blocks(): void
    {
        [$workForm, $graphic] = $this->prepareGraphicDepthFixture('GRAPHIC-DEPTH-STANDARD-001');
        TenantSetting::setValue($workForm->tenant_account_id, 'process_depth', 'standard', 'string');

        $response = $this->showGraphic($workForm, $graphic);
        $content = $response->getContent();

        $response->assertOk()
            ->assertSee('Standart Akış')
            ->assertSee('data-graphic-process-depth="standard"', false)
            ->assertSee('data-graphic-depth-branch="standard"', false)
            ->assertSee('graphic-operation-tabs', false)
            ->assertSee('graphic-action-step-tabs', false)
            ->assertSee('graphic-history-block', false)
            ->assertSee('graphic-operation-status-sidebar', false)
            ->assertDontSee('graphic-depth-fast-panel', false)
            ->assertDontSee('graphic-controlled-attachment-block', false)
            ->assertDontSee('graphic-readiness-sidebar', false)
            ->assertDontSee('graphic-recent-activities-sidebar', false);

        $this->assertSame(1, substr_count($content, 'data-canonical-focus-panel="true"'));
        $this->assertSame(1, substr_count($content, 'pd-graphic-depth-primary-cta'));
    }

    public function test_controlled_depth_renders_attachment_readiness_and_activity_details_in_turkish(): void
    {
        [$workForm, $graphic] = $this->prepareGraphicDepthFixture('GRAPHIC-DEPTH-CONTROLLED-001');
        TenantSetting::setValue($workForm->tenant_account_id, 'process_depth', 'controlled', 'string');

        $response = $this->showGraphic($workForm, $graphic);
        $content = $response->getContent();

        $response->assertOk()
            ->assertSee('Kontrollü Akış')
            ->assertSee('data-graphic-process-depth="controlled"', false)
            ->assertSee('data-graphic-depth-branch="controlled"', false)
            ->assertSee('graphic-operation-tabs', false)
            ->assertSee('graphic-action-step-tabs', false)
            ->assertSee('graphic-history-block', false)
            ->assertSee('graphic-controlled-attachment-block', false)
            ->assertSee('graphic-readiness-sidebar', false)
            ->assertSee('graphic-recent-activities-sidebar', false)
            ->assertSee('Müşteriye Açık')
            ->assertSee('İç Kayıt')
            ->assertSee('Tedarik kaydı oluşturuldu')
            ->assertSee('Üretim operasyonu oluşturuldu')
            ->assertDontSee('Procurement Needed')
            ->assertDontSee('Production Operation Created')
            ->assertDontSee('Delivery Record Created')
            ->assertDontSee('Work Form Created')
            ->assertDontSee('physical_path', false)
            ->assertDontSee('grand_total', false)
            ->assertDontSee('Product Data Hub', false)
            ->assertDontSee('Müşteri onayı zorunlu');

        $this->assertSame(1, substr_count($content, 'data-canonical-focus-panel="true"'));
        $this->assertSame(1, substr_count($content, 'pd-graphic-depth-primary-cta'));
    }

    public function test_each_graphic_depth_renders_distinct_dom_markers_and_response_hashes(): void
    {
        [$workForm, $graphic] = $this->prepareGraphicDepthFixture('GRAPHIC-DEPTH-DIFF-001');

        TenantSetting::setValue($workForm->tenant_account_id, 'process_depth', 'fast', 'string');
        $fast = $this->showGraphic($workForm, $graphic)->getContent();

        TenantSetting::setValue($workForm->tenant_account_id, 'process_depth', 'standard', 'string');
        $standard = $this->showGraphic($workForm->fresh(), $graphic->fresh())->getContent();

        TenantSetting::setValue($workForm->tenant_account_id, 'process_depth', 'controlled', 'string');
        $controlled = $this->showGraphic($workForm->fresh(), $graphic->fresh())->getContent();

        $this->assertStringContainsString('data-graphic-depth-branch="fast"', $fast);
        $this->assertStringContainsString('data-graphic-depth-branch="standard"', $standard);
        $this->assertStringContainsString('data-graphic-depth-branch="controlled"', $controlled);
        $this->assertStringContainsString('graphic-depth-fast-panel', $fast);
        $this->assertStringContainsString('graphic-operation-tabs', $standard);
        $this->assertStringContainsString('graphic-readiness-sidebar', $controlled);
        $this->assertStringNotContainsString('graphic-history-block', $fast);
        $this->assertStringContainsString('graphic-history-block', $standard);
        $this->assertStringContainsString('graphic-controlled-attachment-block', $controlled);
        $this->assertNotSame(md5($fast), md5($standard));
        $this->assertNotSame(md5($standard), md5($controlled));
        $this->assertNotSame(md5($fast), md5($controlled));
    }

    /**
     * @return array{0: OrderItemWorkForm, 1: OrderItemPrintGraphic}
     */
    private function prepareGraphicDepthFixture(string $productCode): array
    {
        $this->enableGraphicCustomerApproval();

        $workForm = $this->createGraphicShowWorkForm($productCode);
        $graphic = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->firstOrFail();

        $this->attachGraphicImage($graphic, 'graphic-customer-visible.png', 'customer_visible');
        $this->attachGraphicImage($graphic->fresh(), 'graphic-internal-proof.png', 'internal');

        OrderItemWorkFormActivityLog::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'action_type' => 'procurement_request_created',
            'visibility' => 'internal',
            'created_by' => $this->graphicAdminUser->id,
        ]);

        OrderItemWorkFormActivityLog::query()->create([
            'tenant_account_id' => $workForm->tenant_account_id,
            'work_form_id' => $workForm->id,
            'order_id' => $workForm->order_id,
            'order_item_id' => $workForm->order_item_id,
            'action_type' => 'production_operation_created',
            'visibility' => 'customer_visible',
            'created_by' => $this->graphicAdminUser->id,
        ]);

        return [$workForm->fresh(), $graphic->fresh()];
    }

    private function showGraphic(OrderItemWorkForm $workForm, OrderItemPrintGraphic $graphic)
    {
        return $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm) . '?operation=' . $graphic->id);
    }
}
