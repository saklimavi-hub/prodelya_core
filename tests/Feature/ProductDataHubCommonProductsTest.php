<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubCommonProductsTest extends TestCase
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

    public function test_common_products_route_redirects_to_standard_products_with_301(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/common-products')
            ->assertStatus(301)
            ->assertRedirect('/admin/super-admin/product-data-hub/standard-products?limit=50');
    }

    public function test_common_products_query_string_is_preserved_or_mapped_for_standard_products(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/common-products?q=0506-L&supplier=Etkin&sellable=quote_hidden&tenant_output=blocked')
            ->assertStatus(301)
            ->assertRedirect('/admin/super-admin/product-data-hub/standard-products?q=0506-L&supplier=Etkin&sellable=not_sellable&tenant_projection_status=blocked&limit=50');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/common-products?search=0506-L')
            ->assertStatus(301)
            ->assertRedirect('/admin/super-admin/product-data-hub/standard-products?q=0506-L&limit=50');
    }

    public function test_standard_products_page_remains_the_technical_depot_screen(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/standard-products?limit=50')
            ->assertOk()
            ->assertSeeText('Teknik Standart Ürünler')
            ->assertSeeText('teknik standart ürün deposudur')
            ->assertSeeText('günlük operasyon/teşhis ekranı Ürün Paneli’dir')
            ->assertSee('value="50"', false);
    }
}
