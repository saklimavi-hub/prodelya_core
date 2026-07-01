<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CatalogSearchController;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Services\TenantCatalog\TenantCatalogListRowQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ProductHubSellableTruthDiagnosticsTest extends TestCase
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

    public function test_product_panel_surfaces_freshness_chain_without_mutating_data(): void
    {
        [$tenant, $source, $product, $variant] = $this->makeVariantScenario([
            'raw_price' => 9.2,
            'raw_stock' => 49800,
            'standard_price' => 6.2,
            'standard_stock' => 107500,
            'projection_price' => 6.2,
            'projection_stock' => 107500,
            'quote_visible' => true,
            'access_open' => true,
            'raw_updated_at' => now(),
            'standard_updated_at' => now()->subDay(),
            'projection_updated_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?search=ET-0506-L');

        $response->assertOk()
            ->assertSeeText('Katalog Fiyatı Eski')
            ->assertSeeText('Katalog yansıması geri kalmış')
            ->assertSeeText('Varyant Eşleşmesi Kontrol')
            ->assertSeeText('Satılabilir varyant')
            ->assertSeeText('9,20 TL')
            ->assertSeeText('6,20 TL');

        $variant->refresh();
        $catalogVariant = TenantCatalogProductVariant::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('standard_product_variant_id', $variant->id)
            ->firstOrFail();

        $this->assertSame(6.2, (float) $variant->min_purchase_price);
        $this->assertSame(6.2, (float) $catalogVariant->display_price);
        $this->assertSame(9.2, (float) data_get($variant->rawVariants()->first(), 'normalized_payload.list_price'));
    }

    public function test_product_panel_hides_stale_price_badge_for_clean_sellable_variant(): void
    {
        $this->makeVariantScenario([
            'raw_price' => 9.2,
            'raw_stock' => 49800,
            'standard_price' => 9.2,
            'standard_stock' => 49800,
            'projection_price' => 9.2,
            'projection_stock' => 49800,
            'quote_visible' => true,
            'access_open' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?search=ET-0506-L&freshness_state=all');

        $response->assertOk()
            ->assertSeeText('Satılabilir varyant')
            ->assertSeeText('Otomatik güncellendi')
            ->assertDontSeeText('Katalog Fiyatı Eski')
            ->assertDontSeeText('Katalog yansıması eski');
    }

    public function test_clean_flow_mode_keeps_normal_rows_out_of_review_queue(): void
    {
        $this->makeVariantScenario([
            'raw_price' => 9.2,
            'raw_stock' => 49800,
            'standard_price' => 9.2,
            'standard_stock' => 49800,
            'projection_price' => 9.2,
            'projection_stock' => 49800,
            'quote_visible' => true,
            'access_open' => true,
        ]);

        $cleanResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?search=ET-0506-L&flow_mode=clean_flow');

        $cleanResponse->assertOk()
            ->assertSeeText('Otomatik güncellendi')
            ->assertSee('value="clean_flow" selected', false);

        $reviewResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?search=ET-0506-L&flow_mode=review_queue');

        $reviewResponse->assertOk()
            ->assertSeeText('Filtrelere uygun ürün bulunamadı.')
            ->assertSeeText('Aktif görünüm');
    }

    public function test_parent_rows_are_technical_and_do_not_surface_as_quote_rows(): void
    {
        [$tenant, , $product] = $this->makeVariantScenario([
            'raw_price' => 9.2,
            'raw_stock' => 49800,
            'standard_price' => 9.2,
            'standard_stock' => 49800,
            'projection_price' => 9.2,
            'projection_stock' => 49800,
            'quote_visible' => true,
            'access_open' => true,
        ]);

        $panelResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?search=ET-0506&sales_state=parent_only');

        $panelResponse->assertOk()
            ->assertSeeText('Grup / parent')
            ->assertSeeText('Teklifte görünmez');

        $request = Request::create('/admin/catalog/search', 'GET', ['q' => 'ET-0506']);
        $request->attributes->set('current_tenant', $tenant);
        $results = app(CatalogSearchController::class)->search($request)->getData(true);

        $this->assertFalse(collect($results)->contains(fn (array $item) => ($item['tenant_catalog_product_id'] ?? null) === $product->tenantCatalogProducts()->first()?->id && ($item['tenant_catalog_product_variant_id'] ?? null) === null));
    }

    public function test_catalog_and_quote_search_read_same_projection_price(): void
    {
        [$tenant] = $this->makeVariantScenario([
            'raw_price' => 9.2,
            'raw_stock' => 49800,
            'standard_price' => 9.2,
            'standard_stock' => 49800,
            'projection_price' => 9.2,
            'projection_stock' => 49800,
            'quote_visible' => true,
            'access_open' => true,
        ]);

        $catalogRows = app(TenantCatalogListRowQueryService::class)
            ->paginate($tenant, ['search' => 'ET-0506-L'], Request::create('/admin/catalog', 'GET'), 'products');
        $catalogRow = collect($catalogRows->items())->first(fn ($row) => ($row->product_code ?? null) === 'ET-0506-L');

        $request = Request::create('/admin/catalog/search', 'GET', ['q' => 'ET-0506-L']);
        $request->attributes->set('current_tenant', $tenant);
        $quoteResults = app(CatalogSearchController::class)->search($request)->getData(true);
        $quoteRow = collect($quoteResults)->first(fn (array $row) => ($row['product_code'] ?? null) === 'ET-0506-L');

        $this->assertNotNull($catalogRow);
        $this->assertNotNull($quoteRow);
        $this->assertSame(9.2, round((float) ($catalogRow->display_price ?? 0), 2));
        $this->assertSame(9.2, round((float) ($quoteRow['display_price'] ?? 0), 2));
    }

    public function test_supplier_access_closed_is_flagged_and_hidden_from_catalog_search(): void
    {
        [$tenant] = $this->makeVariantScenario([
            'raw_price' => 9.2,
            'raw_stock' => 49800,
            'standard_price' => 9.2,
            'standard_stock' => 49800,
            'projection_price' => 9.2,
            'projection_stock' => 49800,
            'quote_visible' => true,
            'access_open' => false,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/product-panel?search=ET-0506-L&freshness_state=supplier_access_closed');

        $response->assertOk()
            ->assertSeeText('Tedarikçi erişimi kapalı');

        $request = Request::create('/admin/catalog/search', 'GET', ['q' => 'ET-0506-L']);
        $request->attributes->set('current_tenant', $tenant);
        $results = app(CatalogSearchController::class)->search($request)->getData(true);

        $this->assertFalse(collect($results)->contains(fn (array $row) => ($row['product_code'] ?? null) === 'ET-0506-L'));
    }

    public function test_common_products_redirects_and_standard_products_stays_technical(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/common-products');

        $response->assertStatus(301)
            ->assertRedirect('/admin/super-admin/product-data-hub/standard-products?limit=50');

        $standardResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/super-admin/product-data-hub/standard-products');

        $standardResponse->assertOk()
            ->assertSeeText('teknik standart ürün deposudur');
    }

    private function makeVariantScenario(array $overrides): array
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Saklimavi Diagnostic',
            'legal_name' => 'Saklimavi Diagnostic Ltd.',
            'slug' => 'saklimavi-diag-' . uniqid(),
            'panel_subdomain' => 'saklimavi-diag-' . uniqid(),
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Etkin Diagnostic Supplier',
            'code' => 'ETKIN-DIAG-' . uniqid(),
            'status' => 'active',
        ]);

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'api',
            'source_name' => 'Etkin Diagnostic Source',
            'status' => 'active',
            'config' => [
                'format' => 'json',
                'profile_key' => 'ETKIN',
                'source_profile_template' => 'ETKIN',
                'sync_policy' => ['sync_frequency' => 'daily'],
            ],
        ]);

        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();

        $rawProduct = SupplierProductRaw::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'ET-0506-S',
            'source_sku' => 'ET-0506-S',
            'source_name' => 'Plastik Kalem',
            'source_category' => 'Plastik Kalemler',
            'source_price' => $overrides['raw_price'],
            'source_currency' => 'TL',
            'source_stock' => $overrides['raw_stock'],
            'supplier_product_id' => 4514,
            'supplier_product_code' => '0506-S',
            'supplier_group_code' => '0506',
            'product_name' => 'Plastik Kalem',
            'supplier_category_name' => 'Plastik Kalemler',
            'standard_category_id' => $category->id,
            'stock_quantity' => $overrides['raw_stock'],
            'purchase_price' => $overrides['raw_price'],
            'currency' => 'TL',
            'vat_rate' => 20,
            'normalized_payload' => [
                'list_price' => $overrides['raw_price'],
                'purchase_price' => $overrides['raw_price'],
                'currency' => 'TL',
                'pricing_policy_type' => 'list_price_only',
                'stock_quantity' => $overrides['raw_stock'],
                'total_variant_stock_quantity' => $overrides['raw_stock'],
            ],
            'sync_status' => 'processed',
            'import_hash' => 'diag-parent-' . uniqid(),
            'updated_at' => $overrides['raw_updated_at'] ?? now(),
            'created_at' => now()->subDays(3),
        ]);

        $product = StandardProduct::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_product_raw_id' => $rawProduct->id,
            'standard_product_code' => 'ET-0506',
            'sku' => 'ET-0506',
            'product_name' => 'Plastik Kalem',
            'base_product_name' => 'Plastik Kalem',
            'name' => 'Plastik Kalem',
            'slug' => 'et-0506',
            'standard_category_id' => $category->id,
            'category' => $category->full_path,
            'product_family' => 'promotion',
            'currency' => 'TL',
            'min_purchase_price' => $overrides['standard_price'],
            'max_purchase_price' => $overrides['standard_price'],
            'total_stock_quantity' => $overrides['standard_stock'],
            'supplier_count' => 1,
            'variant_count' => 1,
            'warning_flag' => false,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_source_id' => $source->id,
                'supplier_product_code' => '0506-S',
                'supplier_group_code' => '0506',
                'supplier_category_name' => 'Plastik Kalemler',
                'list_price' => $overrides['raw_price'],
                'stock_quantity' => $overrides['raw_stock'],
            ]],
            'meta' => [
                'price_snapshot' => ['list_price' => $overrides['standard_price'], 'vat_rate' => 20],
                'supplier_category_path' => 'Promosyon / Kalemler / Plastik Kalemler',
            ],
            'updated_at' => $overrides['standard_updated_at'] ?? now(),
            'created_at' => now()->subDays(2),
        ]);

        $rawProduct->forceFill(['standard_product_id' => $product->id])->save();

        $variant = StandardProductVariant::query()->create([
            'standard_product_id' => $product->id,
            'tenant_account_id' => $tenant->id,
            'variant_code' => '0506-L',
            'generated_variant_code' => 'ET-0506-L',
            'variant_name' => 'Plastik Kalem',
            'variant_color' => 'Lacivert',
            'variant_size' => null,
            'variant_attributes' => [],
            'stock_quantity' => $overrides['standard_stock'],
            'min_purchase_price' => $overrides['standard_price'],
            'max_purchase_price' => $overrides['standard_price'],
            'supplier_count' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [
                'supplier_id' => $supplier->id,
                'supplier_source_id' => $source->id,
                'variant_stock_code' => '0506-L',
                'supplier_product_code' => '0506-L',
                'supplier_group_code' => '0506',
                'list_price' => $overrides['raw_price'],
            ],
            'meta' => [
                'price_snapshot' => ['list_price' => $overrides['standard_price'], 'vat_rate' => 20],
            ],
            'updated_at' => $overrides['standard_updated_at'] ?? now(),
            'created_at' => now()->subDays(2),
        ]);

        SupplierProductVariantRaw::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'supplier_product_raw_id' => $rawProduct->id,
            'standard_product_variant_id' => $variant->id,
            'parent_supplier_product_id' => 4516,
            'supplier_group_code' => '0506',
            'variant_id' => '0506-L',
            'variant_code' => '0506-L',
            'variant_stock_code' => '0506-L',
            'variant_name' => '0506-L Plastik Kalem',
            'variant_color' => 'Lacivert',
            'variant_stock_quantity' => $overrides['raw_stock'],
            'generated_variant_code' => 'ET-0506-L',
            'normalized_payload' => [
                'list_price' => $overrides['raw_price'],
                'purchase_price' => $overrides['raw_price'],
                'currency' => 'TL',
                'pricing_policy_type' => 'list_price_only',
                'variant_stock_quantity' => $overrides['raw_stock'],
                'quote_search_visible' => $overrides['quote_visible'],
            ],
            'sync_status' => 'processed',
            'import_hash' => 'diag-variant-' . uniqid(),
            'updated_at' => $overrides['raw_updated_at'] ?? now(),
            'created_at' => now()->subDays(3),
        ]);

        $catalogProduct = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $product->id,
            'tenant_sku' => 'TEN-ET-0506',
            'name' => 'Tenant Plastik Kalem',
            'product_code' => 'TEN-ET-0506',
            'product_name' => 'Tenant Plastik Kalem',
            'slug' => 'tenant-et-0506',
            'standard_category_id' => $category->id,
            'product_family' => 'promotion',
            'display_price' => $overrides['projection_price'],
            'sale_price' => $overrides['projection_price'],
            'currency' => 'TL',
            'total_stock_quantity' => $overrides['projection_stock'],
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => $overrides['projection_stock'],
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_source_id' => $source->id,
                'supplier_product_code' => '0506-S',
                'supplier_group_code' => '0506',
            ]],
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'last_synced_at' => now(),
            'meta' => ['price_snapshot' => ['list_price' => $overrides['projection_price'], 'vat_rate' => 20], 'is_parent' => true, 'is_sellable' => false],
            'is_active' => true,
            'stock_quantity' => $overrides['projection_stock'],
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
            'updated_at' => $overrides['projection_updated_at'] ?? now(),
            'created_at' => now()->subDay(),
        ]);

        TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $tenant->id,
            'tenant_catalog_product_id' => $catalogProduct->id,
            'standard_product_variant_id' => $variant->id,
            'variant_code' => 'ET-0506-L',
            'variant_name' => 'Plastik Kalem',
            'variant_color' => 'Lacivert',
            'display_price' => $overrides['projection_price'],
            'currency' => 'TL',
            'stock_quantity' => $overrides['projection_stock'],
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => $overrides['projection_stock'],
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => [
                'supplier_id' => $supplier->id,
                'supplier_source_id' => $source->id,
                'supplier_product_code' => '0506-L',
                'supplier_group_code' => '0506',
                'variant_stock_code' => '0506-L',
            ],
            'meta' => [
                'quote_search_visible' => $overrides['quote_visible'],
                'price_snapshot' => ['list_price' => $overrides['projection_price'], 'vat_rate' => 20],
                'supplier_product_code' => '0506-L',
                'supplier_group_code' => '0506',
                'parent_product_name' => 'Plastik Kalem',
            ],
            'updated_at' => $overrides['projection_updated_at'] ?? now(),
            'created_at' => now()->subDay(),
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => $overrides['access_open'],
            'granted_at' => $overrides['access_open'] ? now()->subDay() : null,
            'can_view_products' => $overrides['access_open'],
            'can_request_purchase' => false,
            'can_use_in_quotes' => $overrides['access_open'],
            'price_multiplier' => 1,
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => $overrides['access_open'],
            'export_allowed' => false,
            'meta' => [],
        ]);

        return [$tenant, $source, $product, $variant];
    }
}
