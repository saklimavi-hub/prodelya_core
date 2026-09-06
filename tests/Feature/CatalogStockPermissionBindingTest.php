<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Priority-1 binding: TenantCatalogController / LocalProductController /
 * StockPurchaseController write endpoints were previously reachable by any
 * tenant user (only module/feature flags, no role permission). This proves
 * manage_advanced_catalog and manage_stock are now enforced.
 */
class CatalogStockPermissionBindingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $tenantOwner;
    private User $adminUser;
    private User $salesUser;
    private User $productionUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenant->forceFill(['package_key' => 'enterprise'])->save();

        foreach (['product_variants', 'local_stock'] as $featureKey) {
            TenantModule::query()->updateOrCreate(
                ['tenant_account_id' => $this->tenant->id, 'module_key' => 'advanced_catalog', 'feature_key' => $featureKey],
                ['is_enabled' => true]
            );
        }

        $this->tenantOwner = $this->makeUser('tenant_owner', 'owner-catalog-perm@example.test');
        $this->adminUser = $this->makeUser('admin', 'admin-catalog-perm@example.test');
        // "sales" has view_products only (no manage_advanced_catalog, no manage_stock).
        $this->salesUser = $this->makeUser('sales', 'sales-catalog-perm@example.test');
        // "production" has view_products + manage_stock, but NOT manage_advanced_catalog.
        $this->productionUser = $this->makeUser('production', 'production-catalog-perm@example.test');
    }

    public function test_local_products_store_requires_manage_advanced_catalog_permission(): void
    {
        // production has manage_stock but not manage_advanced_catalog: proves the two
        // permissions are not interchangeable for general catalog writes.
        $this->actingAs($this->productionUser, 'web')
            ->post($this->tenantUrl('/admin/catalog/local-products'), [])
            ->assertForbidden();

        $this->actingAs($this->adminUser, 'web')
            ->post($this->tenantUrl('/admin/catalog/local-products'), [])
            ->assertStatus(302);

        $this->actingAs($this->tenantOwner, 'web')
            ->post($this->tenantUrl('/admin/catalog/local-products'), [])
            ->assertStatus(302);
    }

    public function test_catalog_bulk_visibility_update_requires_manage_advanced_catalog_permission(): void
    {
        $this->actingAs($this->salesUser, 'web')
            ->post($this->tenantUrl('/admin/catalog/visibility/bulk-update'), ['action' => 'save_rows'])
            ->assertForbidden();

        $this->actingAs($this->adminUser, 'web')
            ->post($this->tenantUrl('/admin/catalog/visibility/bulk-update'), ['action' => 'save_rows'])
            ->assertRedirect();

        $this->actingAs($this->tenantOwner, 'web')
            ->post($this->tenantUrl('/admin/catalog/visibility/bulk-update'), ['action' => 'save_rows'])
            ->assertRedirect();
    }

    public function test_catalog_local_stock_write_requires_manage_stock_permission(): void
    {
        $product = $this->makeCatalogProduct();

        // sales has neither manage_advanced_catalog nor manage_stock.
        $this->actingAs($this->salesUser, 'web')
            ->post($this->tenantUrl('/admin/catalog/' . $product->id . '/local-stock'), ['local_stock_quantity' => 5])
            ->assertForbidden();

        // production has manage_stock (but not manage_advanced_catalog) and must be let through here.
        $this->actingAs($this->productionUser, 'web')
            ->post($this->tenantUrl('/admin/catalog/' . $product->id . '/local-stock'), ['local_stock_quantity' => 5])
            ->assertStatus(302);

        $this->actingAs($this->tenantOwner, 'web')
            ->post($this->tenantUrl('/admin/catalog/' . $product->id . '/local-stock'), ['local_stock_quantity' => 5])
            ->assertStatus(302);
    }

    public function test_stock_purchases_store_requires_manage_stock_permission(): void
    {
        $this->actingAs($this->salesUser, 'web')
            ->post($this->tenantUrl('/admin/stock/purchases'), [])
            ->assertForbidden();

        $this->actingAs($this->productionUser, 'web')
            ->post($this->tenantUrl('/admin/stock/purchases'), [])
            ->assertStatus(302);

        $this->actingAs($this->tenantOwner, 'web')
            ->post($this->tenantUrl('/admin/stock/purchases'), [])
            ->assertStatus(302);
    }

    private function makeUser(string $roleKey, string $email): User
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id,
        ]);

        return $user;
    }

    private function makeCatalogProduct(): TenantCatalogProduct
    {
        return TenantCatalogProduct::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'standard_product_id' => null,
            'tenant_sku' => 'SKU-' . uniqid(),
            'name' => 'Perm Binding Test Product',
            'product_code' => 'CAT-' . uniqid(),
            'product_name' => 'Perm Binding Test Product',
            'slug' => 'perm-binding-test-product-' . uniqid(),
            'standard_category_id' => null,
            'product_family' => 'promotion',
            'image_url' => 'https://example.test/product.jpg',
            'display_price' => 100,
            'sale_price' => 100,
            'currency' => 'TL',
            'total_stock_quantity' => 50,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 50,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => [],
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'hidden_reason' => null,
            'is_featured' => false,
            'local_stock_priority' => true,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'last_synced_at' => now(),
            'meta' => [
                'price_snapshot' => ['list_price' => 100, 'vat_rate' => 20],
                'warning_snapshot' => [],
            ],
            'is_active' => true,
            'stock_quantity' => 50,
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
        ]);
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
