<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubFinalUiCleanupTest extends TestCase
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

    public function test_product_data_hub_sidebar_is_grouped_for_daily_category_and_tools(): void
    {
        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/super-admin/product-data-hub')
            ->assertOk()
            ->assertSeeText('Durum Merkezi')
            ->assertSeeText('Tedarikçi Akışları')
            ->assertSeeText('Kategori ve Özellikler')
            ->assertSeeText('Ayarlar ve Bakım')
            ->assertSeeText('Standart Ürünler')
            ->assertSeeText('Akış Kontrol')
            ->assertSeeText('Profil Karşılaştırma');
    }

    public function test_tenant_catalog_hides_technical_product_data_hub_projection_actions(): void
    {
        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/catalog')
            ->assertOk()
            ->assertDontSeeText('Product Data Hub Özeti')
            ->assertDontSeeText('Katalog Projeksiyonunu Güncelle')
            ->assertSeeText('Local Ürün Ekle')
            ->assertSeeText('Uyarılıları Göster');
    }

    public function test_placeholder_export_buttons_are_not_active_actions(): void
    {
        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/product-data-hub/exports')
            ->assertForbidden()
            ->assertSeeText('Özellik erişimi kapalı')
            ->assertSeeText('aktif değil');
    }

    public function test_working_local_csv_import_remains_visible(): void
    {
        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/catalog/local-products')
            ->assertOk()
            ->assertSeeText('CSV Import');
    }

    public function test_category_mapping_explanations_use_badges_and_collapsed_detail(): void
    {
        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/category-mappings?mode=advanced')
            ->assertOk()
            ->assertSeeText('Review Kararı Bekliyor')
            ->assertSeeText('Geçici görünüm olabilir')
            ->assertSeeText('Mapping / Refresh Ayrı')
            ->assertSee('<details class="pd-inline-details mt-3">', false);
    }

    public function test_source_create_and_edit_keep_technical_fields_under_accordion(): void
    {
        $this->actingAs($this->adminUser);

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/sources/create')
            ->assertOk()
            ->assertSeeText('Önizleme ve Alan Eşleme Hazırlığı')
            ->assertSeeText('Sync Davranışı')
            ->assertSee('<details class="pd-card pd-form-card mb-6">', false);

        $this->getOnCentralHost('/admin/super-admin/product-data-hub/sources/1/edit')
            ->assertOk()
            ->assertSeeText('Bağlantı ve Güvenlik')
            ->assertSeeText('Sync Davranışı')
            ->assertSee('<details class="pd-card pd-form-card mb-6">', false);
    }

    private function getOnCentralHost(string $uri)
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get($uri);
    }
}
