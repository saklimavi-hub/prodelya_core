<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductHubCatalogOutputReportsTemplateTest extends TestCase
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

    public function test_catalog_output_screen_explains_selected_tenant_catalog_context(): void
    {
        $tenant = \App\Models\TenantAccount::query()->where('status', 'active')->orderBy('id')->firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/catalog-output?tenant_id=' . $tenant->id);

        $response->assertOk();
        $response->assertSee('Abone Katalog Yayını');
        $response->assertSee('Abone Firma seçin');
        $response->assertSee('Normal kullanımda ayrı “havuza aktar”, “kataloğa aktar” veya “teklife gönder” adımı beklenmez.');
        $response->assertSee('İleri düzey satış listesi güncellemesi yalnız bu Abone Firma için kontrollü çalıştırılır.');
        $response->assertSee('Ürünleri Güncelle');
        $response->assertSee('Boşlukları Tamamla');
        $response->assertSee('Abone Firma Ürün Listesi');
        $response->assertSee('catalog-output/project-refresh', false);
        $response->assertSee('catalog-output/project-missing', false);
        $response->assertDontSee(route('admin.super.product-data-hub.sources.sync-reports', ['review_only' => 1]), false);
    }

    public function test_tenant_supplier_access_screen_explains_catalog_publication_prerequisite(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenant-supplier-access.index'));

        $response->assertOk();
        $response->assertSee('Abone Firma Tedarikçi Erişimleri');
        $response->assertSee('Bir tedarikçinin ürünlerinin Abone Firma ürün listesinde ve teklif aramasında otomatik görünebilmesi için tedarikçi erişimi aktif, katalog görünürlüğü açık ve teklif kullanımı izinli olmalıdır.');
        $response->assertSee('Katalogda Görünsün');
        $response->assertSee('Teklifte Kullanılsın');
        $response->assertSee('Son katalog durumu');
    }

    public function test_sync_reports_screen_shows_price_stock_summary_and_review_focus(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.sync-reports', ['review_only' => 1]));

        $response->assertOk();
        $response->assertSee('Senkron ve Raporlar');
        $response->assertSee('Otomatik Akış ve Review Ayrımı');
        $response->assertSee('Fiyat / Stok Güncelleme Özeti');
        $response->assertSee('Fiyat değişimi');
        $response->assertSee('Stok değişimi');
        $response->assertSee('İnceleme Bekleyenler');
        $response->assertSee('Kimlik / varyant sorunu');
        $response->assertSee('Teknik Detaylar');
    }

    public function test_product_hub_css_keeps_standard_radii_for_catalog_output_and_reports_surfaces(): void
    {
        $css = file_get_contents(public_path('css/prodelya-admin.css'));

        $this->assertStringContainsString('--pd-radius-card: 8px;', $css);
        $this->assertStringContainsString('--pd-radius-panel: 6px;', $css);
        $this->assertStringContainsString('--pd-radius-control: 5px;', $css);
        $this->assertStringContainsString('--pd-radius-pill: 4px;', $css);
    }
}
