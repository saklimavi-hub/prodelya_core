<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StandardCategory;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantCatalogContextAndSupplierFilterTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private Role $tenantOwnerRole;
    private StandardCategory $category;
    private TenantAccount $demoTenant;
    private TenantAccount $saklimaviTenant;
    private User $demoOwner;
    private User $saklimaviOwner;
    private Supplier $pozitron;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();

        $this->demoTenant = TenantAccount::query()->firstOrCreate(
            ['panel_subdomain' => 'demo'],
            [
                'name' => 'Demo Şirketi',
                'legal_name' => 'Demo Şirketi',
                'slug' => 'demo-sirketi',
                'status' => 'active',
                'package_key' => 'enterprise',
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
                'number_format_locale' => 'tr_TR',
            ]
        );

        $this->saklimaviTenant = TenantAccount::query()->create([
            'name' => 'SAKLImavi Reklam',
            'legal_name' => 'SAKLImavi Reklam',
            'slug' => 'saklimavi',
            'panel_subdomain' => 'saklimavi',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $this->demoOwner = $this->makeTenantOwner($this->demoTenant, 'demo-owner@example.test');
        $this->saklimaviOwner = $this->makeTenantOwner($this->saklimaviTenant, 'saklimavi-owner@example.test');

        $this->pozitron = Supplier::query()->firstOrCreate(
            ['code' => 'POZITRON'],
            ['name' => 'Pozitron Promosyon', 'status' => 'active']
        );

        $this->grantSupplierAccess($this->demoTenant, $this->pozitron);
        $this->grantSupplierAccess($this->saklimaviTenant, $this->pozitron);
        $this->createPozitronCatalogRow($this->saklimaviTenant);
    }

    public function test_catalog_shows_active_tenant_name_and_lists_pozitron_in_saklimavi_context(): void
    {
        $response = $this->actingAs($this->saklimaviOwner)
            ->get($this->tenantUrl($this->saklimaviTenant, '/admin/catalog?search=PZ-AN01SY&supplier=' . $this->pozitron->id));

        $response->assertOk();
        $response->assertSeeText('Abone Firma:');
        $response->assertSeeText('SAKLImavi Reklam');
        $response->assertSeeText('PZ-AN01SY');
        $response->assertDontSeeText('Katalog ürünü bulunamadı.');
    }

    public function test_demo_context_can_be_empty_for_unprojected_supplier_and_quote_search_matches_same_tenant(): void
    {
        $catalogResponse = $this->actingAs($this->demoOwner)
            ->get($this->tenantUrl($this->demoTenant, '/admin/catalog?search=PZ-AN01SY&supplier=' . $this->pozitron->id));

        $catalogResponse->assertOk();
        $catalogResponse->assertSeeText('Demo Şirketi');
        $catalogResponse->assertSeeText('Katalog ürünü bulunamadı.');
        $catalogResponse->assertDontSeeText('PZ-AN01SY');

        $this->actingAs($this->saklimaviOwner)
            ->getJson($this->tenantUrl($this->saklimaviTenant, '/admin/catalog/search?q=PZ-AN01SY'))
            ->assertOk()
            ->assertJsonFragment([
                'product_code' => 'PZ-AN01SY',
                'visible_in_quote' => true,
            ]);

        $this->actingAs($this->demoOwner)
            ->getJson($this->tenantUrl($this->demoTenant, '/admin/catalog/search?q=PZ-AN01SY'))
            ->assertOk()
            ->assertJsonMissing([
                'product_code' => 'PZ-AN01SY',
            ]);
    }

    public function test_catalog_shows_platform_admin_context_note_on_central_host(): void
    {
        $response = $this->actingAs($this->platformAdmin)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog');

        $response->assertOk();
        $response->assertSeeText('Abone Firma:');
        $response->assertSeeText('Demo Şirketi');
        $response->assertSeeText('Platform yöneticisi olarak Abone Firma panelindesiniz.');
    }

    public function test_catalog_project_on_central_host_redirects_with_safe_error_instead_of_500(): void
    {
        $response = $this->actingAs($this->platformAdmin)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from('http://' . self::CENTRAL_HOST . '/admin/catalog')
            ->post('/admin/catalog/project');

        $response->assertRedirect('http://' . self::CENTRAL_HOST . '/admin/catalog');
        $response->assertSessionHas('error', 'Abone Firma context’i seçilmeden katalog projeksiyonu çalıştırılamaz.');
    }

    public function test_tenant_user_can_only_view_own_catalog(): void
    {
        $this->actingAs($this->demoOwner)
            ->get($this->tenantUrl($this->saklimaviTenant, '/admin/catalog'))
            ->assertForbidden();
    }

    private function makeTenantOwner(TenantAccount $tenant, string $email): User
    {
        $user = User::query()->create([
            'name' => $tenant->name . ' Owner',
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        return $user;
    }

    private function grantSupplierAccess(TenantAccount $tenant, Supplier $supplier): void
    {
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'visible_in_catalog' => true,
            'can_use_in_quotes' => true,
            'can_request_purchase' => true,
            'price_multiplier' => 1,
            'safe_stock_quantity' => 0,
            'export_allowed' => false,
        ]);
    }

    private function createPozitronCatalogRow(TenantAccount $tenant): void
    {
        $product = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => null,
            'tenant_sku' => 'PZ-AN01',
            'name' => 'PZ-AN01 Anahtarlık',
            'product_code' => 'PZ-AN01',
            'product_name' => 'PZ-AN01 Anahtarlık',
            'slug' => 'pz-an01',
            'standard_category_id' => $this->category->id,
            'product_family' => 'promotion',
            'image_url' => 'https://example.test/pz-an01.jpg',
            'display_price' => 120,
            'sale_price' => 120,
            'currency' => 'TL',
            'total_stock_quantity' => 3000,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 3000,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => [[
                'supplier_id' => $this->pozitron->id,
                'supplier_name' => $this->pozitron->name,
                'supplier_product_code' => 'AN01',
                'supplier_group_code' => 'AN01',
                'supplier_source_id' => $this->pozitron->id,
            ]],
            'visible_in_catalog' => true,
            'visible_in_quote' => false,
            'hidden_reason' => 'Grup ürün olarak katalogda görünür, teklifte varyantları satılır.',
            'is_featured' => false,
            'local_stock_priority' => true,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'last_synced_at' => now(),
            'meta' => [
                'is_parent' => true,
                'is_sellable' => false,
                'quote_search_visible' => false,
                'supplier_group_code' => 'AN01',
                'price_snapshot' => ['list_price' => 120, 'vat_rate' => 20],
            ],
            'is_active' => true,
            'stock_quantity' => 3000,
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
        ]);

        TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'standard_product_variant_id' => null,
            'variant_code' => 'PZ-AN01SY',
            'variant_name' => 'PZ-AN01SY Anahtarlık',
            'variant_color' => 'Siyah',
            'variant_size' => null,
            'image_url' => 'https://example.test/pz-an01sy.jpg',
            'display_price' => 120,
            'currency' => 'TL',
            'stock_quantity' => 3000,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 3000,
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => [
                'supplier_id' => $this->pozitron->id,
                'supplier_name' => $this->pozitron->name,
                'supplier_product_code' => 'AN01SY',
                'supplier_group_code' => 'AN01',
                'supplier_source_id' => $this->pozitron->id,
            ],
            'meta' => [
                'is_variant' => true,
                'is_sellable' => true,
                'quote_search_visible' => true,
                'parent_product_code' => 'PZ-AN01',
                'supplier_group_code' => 'AN01',
                'price_snapshot' => ['list_price' => 120, 'vat_rate' => 20],
            ],
        ]);
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
