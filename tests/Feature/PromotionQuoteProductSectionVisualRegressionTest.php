<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteProductSectionVisualRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_create_screen_keeps_product_section_visual_backbone_and_safe_print_option_parse(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('Ürün kalemleri');
        $response->assertSee('id="product-items-container"', false);
        $response->assertSee('Baskı Ekle');
        $response->assertSee('Ara Eleman Ayarla');
        $response->assertSee('Ara Eleman Gerekli');
        $response->assertSee('Teklif Özeti');
        $response->assertSee('pd-quote-item-group', false);
        $response->assertSee('pd-print-operation', false);
        $response->assertSee("String((optionLabel ?? printRow.print_option) || '').trim();", false);
        $response->assertDontSee("String(optionLabel ?? printRow.print_option || '').trim();", false);
    }
}
