<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use App\Services\ProductDataHub\ProductHubQuoteVisibilityDiagnosticService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductHubQuoteVisibilityDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private TenantAccount $otherTenant;
    private User $tenantOwner;
    private StandardCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $this->tenant = TenantAccount::query()->create([
            'name' => 'Visibility Audit Tenant',
            'legal_name' => 'Visibility Audit Tenant',
            'slug' => 'visibility-audit-tenant',
            'panel_subdomain' => 'visibility-audit-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);
        $this->otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant',
            'legal_name' => 'Other Tenant',
            'slug' => 'other-tenant-' . uniqid(),
            'panel_subdomain' => 'other-tenant-' . uniqid(),
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $this->tenantOwner = User::query()->create([
            'name' => 'Tenant Owner',
            'email' => 'visibility-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->tenantOwner->id,
            'role_id' => Role::query()->where('key', 'tenant_owner')->firstOrFail()->id,
        ]);
    }

    public function test_it_reports_can_use_in_quotes_reason_when_supplier_access_is_closed(): void
    {
        [$source, $product] = $this->makeStandardProduct('QCLOSED-1');
        $this->grantAccess($source->supplier_id, false);

        $audit = app(ProductHubQuoteVisibilityDiagnosticService::class)->audit([
            'tenant_id' => $this->tenant->id,
            'source_id' => $source->id,
        ]);

        $sourceAudit = $audit['sources'][0];

        $this->assertSame(0, $sourceAudit['quote_visible_product_count']);
        $this->assertSame(1, $sourceAudit['reason_counts']['tenant_supplier_access_quote_closed'] ?? 0);
        $this->assertStringContainsString('can_use_in_quotes=false', $sourceAudit['samples'][0]['message']);
    }

    public function test_it_reports_projection_missing_when_catalog_rows_do_not_exist(): void
    {
        [$source, $product] = $this->makeStandardProduct('NOPROJ-1');
        $this->grantAccess($source->supplier_id, true);

        $audit = app(ProductHubQuoteVisibilityDiagnosticService::class)->audit([
            'tenant_id' => $this->tenant->id,
            'source_id' => $source->id,
        ]);

        $sourceAudit = $audit['sources'][0];

        $this->assertSame(1, $sourceAudit['projection_missing_count']);
        $this->assertSame(1, $sourceAudit['reason_counts']['tenant_catalog_product_missing'] ?? 0);
    }

    public function test_missing_category_is_reported_but_does_not_block_quote_visibility(): void
    {
        [$source, $product] = $this->makeStandardProduct('CATWAIT-1', [
            'standard_category_id' => null,
            'meta' => [
                'category_missing_warning' => true,
                'price_snapshot' => ['list_price' => 120],
            ],
        ]);
        $this->grantAccess($source->supplier_id, true);
        $this->createCatalogProduct($product, [
            'standard_category_id' => null,
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'meta' => [
                'category_missing_warning' => true,
                'price_snapshot' => ['list_price' => 120],
            ],
        ]);

        $audit = app(ProductHubQuoteVisibilityDiagnosticService::class)->audit([
            'tenant_id' => $this->tenant->id,
            'source_id' => $source->id,
        ]);

        $sourceAudit = $audit['sources'][0];

        $this->assertSame(1, $sourceAudit['category_pending_count']);
        $this->assertSame(1, $sourceAudit['quote_visible_product_count']);
        $this->assertSame(0, $sourceAudit['invisible_count']);
    }

    public function test_parent_visible_in_quote_false_does_not_hide_sellable_variant_and_quote_search_returns_it(): void
    {
        [$source, $product] = $this->makeStandardProduct('VARVIS-1');
        $variant = StandardProductVariant::query()->create([
            'standard_product_id' => $product->id,
            'variant_code' => 'VARVIS-1-KRM',
            'generated_variant_code' => 'VARVIS-1-KRM',
            'variant_name' => 'Kırmızı',
            'variant_color' => 'Kırmızı',
            'variant_size' => 'M',
            'variant_attributes' => ['size' => 'M'],
            'image_url' => 'https://example.test/varvis-1.jpg',
            'stock_quantity' => 15,
            'min_purchase_price' => 120,
            'max_purchase_price' => 120,
            'supplier_count' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => ['variant_stock_code' => 'VARVIS-1-KRM'],
            'meta' => ['price_snapshot' => ['list_price' => 120]],
        ]);

        $this->grantAccess($source->supplier_id, true);
        $catalogProduct = $this->createCatalogProduct($product, [
            'visible_in_catalog' => true,
            'visible_in_quote' => false,
            'meta' => [
                'price_snapshot' => ['list_price' => 120],
                'is_parent' => true,
            ],
        ]);
        TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $catalogProduct->id,
            'standard_product_variant_id' => $variant->id,
            'variant_code' => 'VARVIS-1-KRM',
            'variant_name' => 'Kırmızı',
            'variant_color' => 'Kırmızı',
            'variant_size' => 'M',
            'image_url' => 'https://example.test/varvis-1.jpg',
            'display_price' => 120,
            'currency' => 'TL',
            'stock_quantity' => 15,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 15,
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => ['variant_stock_code' => 'VARVIS-1-KRM'],
            'meta' => [
                'quote_search_visible' => true,
                'parent_product_name' => $product->product_name,
                'price_snapshot' => ['list_price' => 120],
            ],
        ]);

        $audit = app(ProductHubQuoteVisibilityDiagnosticService::class)->audit([
            'tenant_id' => $this->tenant->id,
            'source_id' => $source->id,
        ]);

        $sourceAudit = $audit['sources'][0];

        $this->assertSame(1, $sourceAudit['quote_visible_variant_count']);
        $this->assertSame(0, $sourceAudit['invisible_count']);
        $this->assertSame(1, $sourceAudit['parent_variant_visibility_problem_count']);

        $response = $this->actingAs($this->tenantOwner, 'web')
            ->getJson($this->tenantUrl('/admin/catalog/search?q=VARVIS-1-KRM'));

        $response->assertOk()
            ->assertJsonFragment([
                'product_code' => 'VARVIS-1-KRM',
                'tenant_catalog_product_id' => $catalogProduct->id,
            ]);
    }

    public function test_variant_level_quote_visibility_reason_is_reported(): void
    {
        [$source, $product] = $this->makeStandardProduct('VARHIDE-1');
        $variant = StandardProductVariant::query()->create([
            'standard_product_id' => $product->id,
            'variant_code' => 'VARHIDE-1-KRM',
            'generated_variant_code' => 'VARHIDE-1-KRM',
            'variant_name' => 'Kırmızı',
            'variant_color' => 'Kırmızı',
            'variant_size' => 'M',
            'variant_attributes' => ['size' => 'M'],
            'image_url' => 'https://example.test/varhide-1.jpg',
            'stock_quantity' => 15,
            'min_purchase_price' => 120,
            'max_purchase_price' => 120,
            'supplier_count' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => ['variant_stock_code' => 'VARHIDE-1-KRM'],
            'meta' => ['price_snapshot' => ['list_price' => 120]],
        ]);

        $this->grantAccess($source->supplier_id, true);
        $catalogProduct = $this->createCatalogProduct($product, ['visible_in_catalog' => true, 'visible_in_quote' => false]);
        TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $catalogProduct->id,
            'standard_product_variant_id' => $variant->id,
            'variant_code' => 'VARHIDE-1-KRM',
            'variant_name' => 'Kırmızı',
            'variant_color' => 'Kırmızı',
            'variant_size' => 'M',
            'image_url' => 'https://example.test/varhide-1.jpg',
            'display_price' => 120,
            'currency' => 'TL',
            'stock_quantity' => 15,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 15,
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => ['variant_stock_code' => 'VARHIDE-1-KRM'],
            'meta' => [
                'quote_search_visible' => false,
                'parent_product_name' => $product->product_name,
                'price_snapshot' => ['list_price' => 120],
            ],
        ]);

        $audit = app(ProductHubQuoteVisibilityDiagnosticService::class)->audit([
            'tenant_id' => $this->tenant->id,
            'source_id' => $source->id,
        ]);

        $sourceAudit = $audit['sources'][0];

        $this->assertSame(1, $sourceAudit['reason_counts']['tenant_catalog_variant_quote_closed'] ?? 0);
        $this->assertStringContainsString('meta.quote_search_visible=false', $sourceAudit['samples'][0]['message']);
    }

    public function test_command_reports_visibility_summary_and_tenant_scope(): void
    {
        [$source, $product] = $this->makeStandardProduct('CMD-1');
        $this->grantAccess($source->supplier_id, true);
        $this->createCatalogProduct($product, ['visible_in_catalog' => true, 'visible_in_quote' => true]);

        $this->artisan('product-data-hub:quote-visibility-audit', [
            '--tenant' => $this->tenant->slug,
            '--supplier-id' => $source->supplier_id,
            '--source-id' => $source->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Abone Firma: ' . $this->tenant->name)
            ->expectsOutputToContain('Tedarikçi: ' . $source->supplier->name)
            ->expectsOutputToContain('Kaynak: ' . $source->source_name)
            ->expectsOutputToContain('Dry-run: Veri yazılmadı.')
            ->assertExitCode(0);
    }

    public function test_tenant_user_cannot_open_super_admin_product_hub_sources_screen(): void
    {
        $response = $this->actingAs($this->tenantOwner, 'web')
            ->get(route('admin.super.product-data-hub.sources.index'));

        $this->assertNotSame(200, $response->getStatusCode());
    }

    private function makeStandardProduct(string $code, array $overrides = []): array
    {
        $supplier = Supplier::query()->create([
            'name' => 'Visibility Supplier ' . $code,
            'code' => 'VIS-' . $code . '-' . uniqid(),
            'status' => 'active',
        ]);

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Visibility Source ' . $code,
            'status' => 'active',
            'config' => [
                'profile_key' => 'AKDENIZ',
                'source_profile_template' => 'AKDENIZ',
            ],
        ]);

        $product = StandardProduct::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'standard_product_code' => $code,
            'sku' => $code,
            'product_name' => 'Visibility Product ' . $code,
            'base_product_name' => 'Visibility Product ' . $code,
            'name' => 'Visibility Product ' . $code,
            'slug' => 'visibility-product-' . strtolower($code),
            'standard_category_id' => $this->category->id,
            'product_family' => 'promotion',
            'image_url' => 'https://example.test/' . strtolower($code) . '.jpg',
            'currency' => 'TL',
            'min_purchase_price' => 120,
            'max_purchase_price' => 120,
            'total_stock_quantity' => 10,
            'supplier_count' => 1,
            'variant_count' => 0,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_source_id' => $source->id,
                'supplier_name' => $supplier->name,
                'supplier_product_code' => $code,
            ]],
            'meta' => [
                'price_snapshot' => ['list_price' => 120],
            ],
            'is_active' => true,
        ], $overrides));

        return [$source, $product];
    }

    private function createCatalogProduct(StandardProduct $product, array $overrides = []): TenantCatalogProduct
    {
        return TenantCatalogProduct::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'standard_product_id' => $product->id,
            'tenant_sku' => $product->standard_product_code,
            'name' => $product->product_name,
            'product_code' => $product->standard_product_code,
            'product_name' => $product->product_name,
            'slug' => 'catalog-' . strtolower($product->standard_product_code),
            'standard_category_id' => $product->standard_category_id,
            'product_family' => 'promotion',
            'image_url' => $product->image_url,
            'display_price' => 120,
            'sale_price' => 120,
            'currency' => 'TL',
            'total_stock_quantity' => 10,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 10,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => $product->source_summary,
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'last_synced_at' => now(),
            'meta' => [
                'price_snapshot' => ['list_price' => 120],
            ],
            'is_active' => true,
        ], $overrides));
    }

    private function grantAccess(int $supplierId, bool $quoteOpen): void
    {
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplierId,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
            'can_use_in_quotes' => $quoteOpen,
            'visible_in_catalog' => true,
            'export_allowed' => false,
            'price_multiplier' => 1,
            'safe_stock_quantity' => 0,
        ]);
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
