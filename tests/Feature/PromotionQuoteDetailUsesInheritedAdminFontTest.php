<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailUsesInheritedAdminFontTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_component_uses_inherited_admin_font(): void
    {
        $css = File::get(public_path('css/prodelya-admin.css'));

        $this->assertStringContainsString('.promotion-quote-detail.quote-detail-compact {', $css);
        $this->assertStringContainsString('font-family: inherit;', $css);
        $this->assertStringNotContainsString('.promotion-quote-detail.quote-detail-compact {'.PHP_EOL.'  --quote-detail-line: #e5ebf3;'.PHP_EOL.'  --quote-detail-soft: #eef2f7;'.PHP_EOL.'  --quote-detail-muted: #66758f;'.PHP_EOL.'  --quote-detail-text: #17243b;'.PHP_EOL.'  --quote-detail-card-bg: #ffffff;'.PHP_EOL.'  --quote-detail-soft-bg: #fbfdff;'.PHP_EOL.'  --quote-detail-accent-bg: #f6f9ff;'.PHP_EOL.'  --quote-detail-accent-line: #d6e4ff;'.PHP_EOL.'  --quote-detail-page-bg: #f6f7f9;'.PHP_EOL.'  color: var(--quote-detail-text);'.PHP_EOL.'  font-family: Arial, Helvetica, sans-serif;', $css);
    }
}
