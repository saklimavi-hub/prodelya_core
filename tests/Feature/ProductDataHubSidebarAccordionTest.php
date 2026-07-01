<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubSidebarAccordionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_product_data_hub_group_is_open_on_hub_route(): void
    {
        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/super-admin/product-data-hub')
            ->assertOk()
            ->assertSee('<details class="pd-sidebar-group is-open"', false)
            ->assertSee('data-sidebar-group="product-data-hub"', false)
            ->assertSee(' open >', false)
            ->assertSeeText('Genel Bakış')
            ->assertDontSee('Product Data Hub · Genel Bakış');
    }

    public function test_product_data_hub_group_stays_open_on_standard_categories_route(): void
    {
        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/super-admin/standard-categories')
            ->assertOk()
            ->assertSee('<details class="pd-sidebar-group is-open"', false)
            ->assertSee('data-sidebar-group="product-data-hub"', false)
            ->assertSee(' open >', false)
            ->assertSeeText('Standart Kategori Ağacı');
    }

    public function test_product_data_hub_group_stays_open_on_tenant_supplier_access_route(): void
    {
        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/super-admin/tenant-supplier-access')
            ->assertOk()
            ->assertSee('<details class="pd-sidebar-group is-open"', false)
            ->assertSee('data-sidebar-group="product-data-hub"', false)
            ->assertSee(' open >', false)
            ->assertSeeText('Abone Firma Tedarikçi Erişimleri');
    }

    public function test_sidebar_submenu_links_use_short_labels_and_correct_urls(): void
    {
        $this->actingAs($this->adminUser);

        $response = $this->getOnCentralHost('/admin/super-admin/product-data-hub/catalog-output');

        $response->assertOk();
        $response->assertSee(route('admin.super.product-data-hub.sources.index'), false);
        $response->assertDontSee('/product-data-hub/sources/create', false);
        $response->assertDontSee(route('admin.super.product-data-hub.common-products'), false);
        $response->assertSee(route('admin.super.product-data-hub.pipeline'), false);
        $response->assertSee(route('admin.super.product-data-hub.sources.sync-reports'), false);
        $response->assertSee(route('admin.super.product-data-hub.category-mappings.index'), false);
        $response->assertSee(route('admin.super.product-data-hub.category-cleanup.index'), false);
        $response->assertSee(route('admin.super.product-data-hub.category-feature-templates.index'), false);
        $response->assertSee(route('admin.super.standard-categories.index'), false);
        $response->assertSee(route('admin.super.tenant-supplier-access.index'), false);
        $response->assertSee(route('admin.super.product-data-hub.standard-products.index'), false);
        $response->assertSee(route('admin.super.product-data-hub.catalog-output'), false);
        $response->assertSeeText('Durum Merkezi');
        $response->assertSeeText('Tedarikçi Akışları');
        $response->assertSeeText('Ürün Havuzu');
        $response->assertSeeText('Kategori ve Özellikler');
        $response->assertSeeText('Abone Katalog Yayını');
        $response->assertSeeText('Senkron / Raporlar');
        $response->assertSeeText('Ayarlar ve Bakım');
        $response->assertSeeText('Genel Bakış');
        $response->assertSeeText('Tedarikçi Kaynakları');
        $response->assertSeeText('Ürün Paneli');
        $response->assertSeeText('Standart Ürünler');
        $response->assertSeeText('Standart Kategori Ağacı');
        $response->assertSeeText('Kategori Eşleme');
        $response->assertSeeText('Kategori Temizlik');
        $response->assertSeeText('Özellik Şablonları');
        $response->assertSeeText('Abone Firma Katalog Çıkışları');
        $response->assertSeeText('Abone Firma Tedarikçi Erişimleri');
        $response->assertSeeText('Senkron ve Raporlar');
        $response->assertSeeText('Akış Kontrol');
        $response->assertSeeText('Profil Karşılaştırma');
        $response->assertDontSee('Product Data Hub · Tedarikçi Kaynakları');
        $response->assertDontSee('Product Data Hub · Abone Firma Katalog Çıkışları');
        $response->assertDontSee('Araçlar · Akış Kontrol');
        $response->assertDontSee('Araçlar · Profil Karşılaştırma');
        $response->assertDontSee('Standart Ürün Görünümü');
    }

    private function getOnCentralHost(string $uri)
    {
        return $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])->get($uri);
    }
}
