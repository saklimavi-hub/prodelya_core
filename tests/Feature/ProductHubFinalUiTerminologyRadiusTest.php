<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductHubFinalUiTerminologyRadiusTest extends TestCase
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

    public function test_daily_product_hub_screens_use_abone_firma_language(): void
    {
        $sources = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.index'));

        $sources->assertOk();
        $sources->assertSee('Detaya Git');
        $sources->assertSee('Abone Firma');
        $sources->assertDontSee('Tenant Çıkışları');

        $catalogOutput = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.catalog-output'));

        $catalogOutput->assertOk();
        $catalogOutput->assertSee('Abone Katalog Yayını');
        $catalogOutput->assertSee('İleri düzey satış listesi güncellemesi yalnız seçili Abone Firma context’iyle çalışır.');
        $catalogOutput->assertDontSee('Tenant Çıkışları');
    }

    public function test_catalog_screen_shows_active_abone_firma_context(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.catalog.index'));

        $response->assertOk();
        $response->assertSee('Abone Firma:');
        $response->assertSee('Katalog Ürünleri');
        $response->assertDontSee('Tenant kataloğundaki tüm ürünler');
    }

    public function test_product_hub_css_and_inline_views_do_not_keep_large_local_radius_values(): void
    {
        $css = file_get_contents(public_path('css/prodelya-admin.css'));

        $this->assertStringContainsString('--pd-radius-card: 8px;', $css);
        $this->assertStringContainsString('--pd-radius-panel: 6px;', $css);
        $this->assertStringContainsString('--pd-radius-control: 5px;', $css);
        $this->assertStringNotContainsString('border-radius:22px', $css);
        $this->assertStringNotContainsString('border-radius:18px', $css);

        $categoryReviewBatch = file_get_contents(resource_path('views/super-admin/product-data-hub/category-review-batch.blade.php'));
        $productPanel = file_get_contents(resource_path('views/super-admin/product-data-hub/product-panel.blade.php'));

        $this->assertStringNotContainsString('border-radius:22px', $categoryReviewBatch);
        $this->assertStringNotContainsString('border-radius:18px', $categoryReviewBatch);
        $this->assertStringNotContainsString('style="position:fixed;inset:0;', $productPanel);
    }
}
