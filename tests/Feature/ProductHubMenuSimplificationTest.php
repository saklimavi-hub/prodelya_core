<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Models\User;
use App\Services\AdminMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProductHubMenuSimplificationTest extends TestCase
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

    public function test_super_admin_product_hub_menu_shows_seven_main_headings(): void
    {
        $menu = app(AdminMenuService::class)->superAdminMenu($this->adminUser);
        $labels = $this->flattenLabels($menu);

        $this->assertContains('Durum Merkezi', $labels);
        $this->assertContains('Tedarikçi Akışları', $labels);
        $this->assertContains('Ürün Havuzu', $labels);
        $this->assertContains('Kategori ve Özellikler', $labels);
        $this->assertContains('Abone Katalog Yayını', $labels);
        $this->assertContains('Senkron / Raporlar', $labels);
        $this->assertContains('Ayarlar ve Bakım', $labels);
    }

    public function test_menu_headings_keep_existing_routes_reachable_under_new_groups(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.index'));

        $response->assertOk();
        $response->assertSee(route('admin.super.product-data-hub.index'), false);
        $response->assertSee(route('admin.super.product-data-hub.sources.index'), false);
        $response->assertSee(route('admin.super.product-data-hub.product-panel'), false);
        $response->assertDontSee(route('admin.super.product-data-hub.common-products'), false);
        $response->assertSee(route('admin.super.product-data-hub.standard-products.index'), false);
        $response->assertSee(route('admin.super.product-data-hub.category-mappings.index'), false);
        $response->assertSee(route('admin.super.standard-categories.index'), false);
        $response->assertSee(route('admin.super.product-data-hub.category-cleanup.index'), false);
        $response->assertSee(route('admin.super.product-data-hub.category-feature-templates.index'), false);
        $response->assertSee(route('admin.super.product-data-hub.catalog-output'), false);
        $response->assertSee(route('admin.super.tenant-supplier-access.index'), false);
        $response->assertSee(route('admin.super.product-data-hub.sources.sync-reports'), false);
        $response->assertSee(route('admin.super.product-data-hub.profile-comparison'), false);
        $response->assertSee(route('admin.super.product-data-hub.pipeline'), false);
    }

    public function test_tenant_user_does_not_see_super_admin_product_hub_menu(): void
    {
        TenantAccount::query()->firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertDontSee('Durum Merkezi');
        $response->assertDontSee('Tedarikçi Akışları');
        $response->assertDontSee('Ürün Havuzu');
        $response->assertDontSee('Kategori ve Özellikler');
        $response->assertDontSee('Abone Katalog Yayını');
        $response->assertDontSee('Senkron / Raporlar');
        $response->assertDontSee('Ayarlar ve Bakım');
        $response->assertDontSee('data-sidebar-group="product-data-hub"', false);
        $response->assertDontSee(route('admin.super.product-data-hub.index'), false);
    }

    public function test_super_admin_product_hub_routes_keep_protective_middleware(): void
    {
        $routeNames = [
            'admin.super.product-data-hub.index',
            'admin.super.product-data-hub.sources.index',
            'admin.super.product-data-hub.product-panel',
            'admin.super.product-data-hub.common-products',
            'admin.super.product-data-hub.standard-products.index',
            'admin.super.product-data-hub.category-mappings.index',
            'admin.super.standard-categories.index',
            'admin.super.product-data-hub.category-cleanup.index',
            'admin.super.product-data-hub.category-feature-templates.index',
            'admin.super.product-data-hub.catalog-output',
            'admin.super.tenant-supplier-access.index',
            'admin.super.product-data-hub.sources.sync-reports',
            'admin.super.product-data-hub.profile-comparison',
            'admin.super.product-data-hub.pipeline',
        ];

        foreach ($routeNames as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, 'Route missing: ' . $routeName);
            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth:web', $middleware, 'auth:web missing for ' . $routeName);
            $this->assertContains('central.access', $middleware, 'central.access missing for ' . $routeName);
            $this->assertContains('super.admin', $middleware, 'super.admin missing for ' . $routeName);
        }
    }

    public function test_common_products_menu_item_is_removed_but_route_stays_reachable(): void
    {
        $menu = app(AdminMenuService::class)->superAdminMenu($this->adminUser);
        $labels = $this->flattenLabels($menu);

        $this->assertNotContains('Ortak Ürün Havuzu (Teknik)', $labels);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.common-products'))
            ->assertStatus(301)
            ->assertRedirect('/admin/super-admin/product-data-hub/standard-products?limit=50');
    }

    private function flattenLabels(array $items): array
    {
        $labels = [];

        foreach ($items as $item) {
            if (!empty($item['label'])) {
                $labels[] = $item['label'];
            }

            if (!empty($item['children']) && is_array($item['children'])) {
                $labels = array_merge($labels, $this->flattenLabels($item['children']));
            }
        }

        return $labels;
    }
}
