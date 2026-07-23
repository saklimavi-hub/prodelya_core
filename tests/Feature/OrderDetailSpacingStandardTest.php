<?php

namespace Tests\Feature;

use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderDetailSpacingStandardTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_order_detail_uses_canonical_spacing_primitives_without_global_card_margin_hacks(): void
    {
        $order = $this->createConvertedOrderForShow(['has_print' => true]);
        TenantSetting::setValue($order->tenant_account_id, 'process_depth', 'standard', 'string');

        $response = $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', $order));

        $content = $response->getContent();
        $css = file_get_contents(public_path('css/prodelya-admin.css'));
        $view = file_get_contents(resource_path('views/admin/orders/show.blade.php'));

        $response->assertOk()
            ->assertSee('class="pd-page-stack"', false)
            ->assertSee('class="pd-two-column-layout pd-order-sticky-layout"', false)
            ->assertSee('class="pd-page-stack pd-order-sticky-main"', false)
            ->assertSee('class="pd-order-summary-panel pd-section-stack pd-order-sticky-sidebar"', false)
            ->assertSee('class="pd-card-body pd-card-stack pd-order-sticky-flow', false);

        $this->assertStringContainsString('--pd-space-page: var(--pd-gap);', $css);
        $this->assertStringContainsString('--pd-space-section: var(--pd-space-12);', $css);
        $this->assertStringContainsString('--pd-space-card: var(--pd-space-10);', $css);
        $this->assertStringContainsString('--pd-space-inline: var(--pd-space-8);', $css);
        $this->assertStringContainsString('--pd-space-tight: var(--pd-space-6);', $css);
        $this->assertStringContainsString('.pd-page-stack', $css);
        $this->assertStringContainsString('.pd-section-stack', $css);
        $this->assertStringContainsString('.pd-card-stack', $css);
        $this->assertStringContainsString('.pd-two-column-layout', $css);
        $this->assertStringContainsString('@media (max-width: 760px)', $css);
        $this->assertStringContainsString('gap: var(--pd-space-page);', $css);
        $this->assertStringContainsString('gap: var(--pd-space-section);', $css);
        $this->assertStringContainsString('gap: var(--pd-space-card);', $css);
        $this->assertStringContainsString('gap: var(--pd-space-inline);', $css);
        $this->assertStringNotContainsString('.card { margin-bottom:', $css);
        $this->assertStringNotContainsString('.panel { margin-bottom:', $css);
        $this->assertStringNotContainsString('.box { margin-bottom:', $css);
        $this->assertDoesNotMatchRegularExpression('/\.(card|panel|box)\s*\{[^}]*margin-bottom\s*:/i', $css);
        $this->assertStringNotContainsString('class="pd-card" style="margin-bottom:', $view);
        $this->assertTrue(substr_count($content, 'pd-page-stack') >= 2);
        $this->assertSame(1, substr_count($content, 'pd-two-column-layout pd-order-sticky-layout'));
        $this->assertSame(1, substr_count($content, 'pd-order-summary-panel pd-section-stack pd-order-sticky-sidebar'));
    }
}
