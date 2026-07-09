<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductHubProductRoleAndCatalogVisibilityLabelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
    }

    public function test_product_hub_pages_explain_their_roles_more_clearly(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel')
            ->assertOk()
            ->assertSeeText('Bekleyen Kontroller')
            ->assertSeeText('Kontrol başlatmak için arama veya filtre seçin.')
            ->assertSeeText('Karar Bekleyen Başlıklar')
            ->assertSeeText('Normal fiyat ve stok senkronizasyonu otomatik çalışır')
            ->assertSeeText('Güncellik Özeti')
            ->assertDontSeeText('Supheli')
            ->assertDontSeeText('Urun')
            ->assertDontSeeText('Guncel');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/common-products')
            ->assertStatus(301)
            ->assertRedirect('/admin/super-admin/product-data-hub/standard-products?limit=50');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/standard-products')
            ->assertOk()
            ->assertSeeText('Teknik Standart Ürünler')
            ->assertSeeText('teknik standart ürün deposudur')
            ->assertSeeText('günlük operasyon/teşhis ekranı Ürün Paneli’dir');
    }
}
