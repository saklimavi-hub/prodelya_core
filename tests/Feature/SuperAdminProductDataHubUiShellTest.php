<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminProductDataHubUiShellTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_product_data_hub_screens_render_shared_shell_blocks(): void
    {
        $screens = [
            route('admin.super.product-data-hub.index') => ['Senkron Sonuç Merkezi', 'Bugün aksiyon gereken ürün var mı?', 'pd-kpi-strip'],
            route('admin.super.product-data-hub.category-mappings.index', ['mode' => 'advanced']) => ['Filtre ve Kuyruk', 'Manuel Review Listesi', 'pd-kpi-strip'],
            route('admin.super.standard-categories.index') => ['Standart Kategori Ağacı', 'Kategori Notları', 'pd-kpi-strip'],
            route('admin.super.product-data-hub.sources.index') => ['Tedarikçi Akışları', 'Tedarikçi Listesi', 'pd-kpi-strip'],
            route('admin.super.product-data-hub.catalog-output') => ['Abone Katalog Yayını', 'Katalog Yayını Özeti', 'pd-mini-kpi-strip'],
            route('admin.super.product-data-hub.standard-products.index') => ['Teknik Standart Ürünler', 'Standart Ürün Listesi', 'pd-kpi-strip'],
            route('admin.super.product-data-hub.pipeline') => ['Akış Kontrol', 'Teknik Akış Özeti', 'pd-mini-kpi-strip'],
            route('admin.super.product-data-hub.profile-comparison') => ['Tedarikçi Profil Karşılaştırma', 'Standart Veri Akışı', 'pd-mini-kpi-strip'],
        ];

        foreach ($screens as $route => $assertions) {
            $response = $this->actingAs($this->platformAdmin, 'web')
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->get($route);

            $response->assertOk();

            foreach ($assertions as $assertion) {
                $response->assertSee($assertion, false);
            }
        }
    }
}
