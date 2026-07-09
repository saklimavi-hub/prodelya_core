<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailCssNamespaceSmokeTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_quote_detail_uses_namespaced_css_hooks_for_pilot_layout(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());
        $css = File::get(public_path('css/prodelya-admin.css'));

        $response->assertOk();
        $response->assertSee('pd-quote-detail', false);
        $response->assertSee('pd-sticky-bar', false);
        $response->assertSee('pd-product-line', false);
        $response->assertSee('pd-print-line', false);
        $response->assertSee('pd-product-print-block__row--product', false);
        $response->assertSee('pd-product-print-block__row--print', false);

        $this->assertStringContainsString('.pd-quote-detail {', $css);
        $this->assertStringContainsString('.pd-quote-detail .pd-product-line__signals {', $css);
        $this->assertStringContainsString('.pd-quote-detail .pd-product-print-block__row {', $css);
        $this->assertStringContainsString('.pd-quote-detail .pd-product-print-block__head,', $css);
        $this->assertStringContainsString('--pd-font-family: Arial, Helvetica, sans-serif;', $css);
        $this->assertStringContainsString('.pd-btn--primary {', $css);
        $this->assertStringContainsString('.pd-chip--warning {', $css);
        $this->assertStringContainsString('.pd-tabs__button {', $css);
        $this->assertStringContainsString('.pd-modal__head,', $css);
        $this->assertStringContainsString('font-family: Arial, Helvetica, sans-serif;', $css);
        $this->assertStringContainsString('.pd-sticky-bar.quote-bottom-bar {', $css);
        $this->assertStringNotContainsString('.btn {', $css);
        $this->assertStringNotContainsString('.card {', $css);
        $this->assertStringNotContainsString('.chip {', $css);
        $this->assertStringNotContainsString('.tab {', $css);
        $this->assertStringNotContainsString('.modal {', $css);
    }
}
