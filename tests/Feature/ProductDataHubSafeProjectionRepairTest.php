<?php

namespace Tests\Feature;

use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDataHubSafeProjectionRepairTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private TenantAccount $tenant;
    private StandardCategory $category;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->create([
            'name' => 'Repair Tenant',
            'legal_name' => 'Repair Tenant',
            'slug' => 'repair-tenant',
            'panel_subdomain' => 'repair-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);
        $this->category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $this->supplier = Supplier::query()->create([
            'name' => 'Repair Supplier',
            'code' => 'REPAIR-SUP',
            'status' => 'active',
        ]);
    }

    public function test_missing_only_dry_run_reports_only_missing_records_without_writing(): void
    {
        $first = $this->createStandardProduct('REP-001', 'Repair One');
        $this->createStandardVariant($first, 'REP-001-SIYAH', 'Siyah');
        $second = $this->createStandardProduct('REP-002', 'Repair Two');
        $this->createStandardVariant($second, 'REP-002-BEYAZ', 'Beyaz');

        $this->grantSupplierAccess(true, true, true, true);
        $this->createCatalogProjection($first);

        $this->artisan('prodelya:project-tenant-catalog', [
            '--tenant' => $this->tenant->slug,
            '--supplier' => $this->supplier->code,
            '--missing-only' => true,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Mod: missing-only repair')
            ->expectsOutputToContain('Aday standard ürün: 1')
            ->expectsOutputToContain('Oluşturulacak katalog ürünü: 1')
            ->expectsOutputToContain('Güncellenecek katalog ürünü: 0')
            ->expectsOutputToContain('Dry-run: Veri yazılmadı.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('tenant_catalog_products', 1);
        $this->assertDatabaseCount('tenant_catalog_product_variants', 1);
    }

    public function test_missing_only_apply_creates_missing_supplier_projection_rows(): void
    {
        $first = $this->createStandardProduct('REP-101', 'Repair Product 101');
        $this->createStandardVariant($first, 'REP-101-SIYAH', 'Siyah');
        $this->createStandardVariant($first, 'REP-101-BEYAZ', 'Beyaz');

        $second = $this->createStandardProduct('REP-102', 'Repair Product 102');
        $this->createStandardVariant($second, 'REP-102-KIRMIZI', 'Kırmızı');

        $this->grantSupplierAccess(true, true, true, true);

        $this->artisan('prodelya:project-tenant-catalog', [
            '--tenant' => $this->tenant->slug,
            '--supplier' => $this->supplier->code,
            '--missing-only' => true,
        ])
            ->expectsOutputToContain('Projection tamamlandı.')
            ->expectsOutputToContain('Yeni oluşturulan katalog ürünü: 2')
            ->assertExitCode(0);

        $this->assertDatabaseCount('tenant_catalog_products', 2);
        $this->assertDatabaseCount('tenant_catalog_product_variants', 3);
    }

    public function test_missing_only_repairs_only_gap_records_without_rewriting_existing_projection(): void
    {
        CarbonImmutable::setTestNow('2026-06-25 10:00:00');

        $existingProduct = $this->createStandardProduct('REP-201', 'Existing Projection Product');
        $existingVariant = $this->createStandardVariant($existingProduct, 'REP-201-SIYAH', 'Siyah');
        $missingProduct = $this->createStandardProduct('REP-202', 'Missing Projection Product');
        $missingVariant = $this->createStandardVariant($missingProduct, 'REP-202-KIRMIZI', 'Kırmızı');

        $this->grantSupplierAccess(true, true, true, true);
        $catalogProduct = $this->createCatalogProjection($existingProduct);
        $catalogVariant = TenantCatalogProductVariant::query()->firstOrFail();
        $originalProductUpdatedAt = $catalogProduct->updated_at;
        $originalVariantUpdatedAt = $catalogVariant->updated_at;

        CarbonImmutable::setTestNow('2026-06-25 12:00:00');

        $this->artisan('prodelya:project-tenant-catalog', [
            '--tenant' => $this->tenant->slug,
            '--supplier' => $this->supplier->code,
            '--missing-only' => true,
        ])->assertExitCode(0);

        $catalogProduct->refresh();
        $catalogVariant->refresh();

        $this->assertSame($originalProductUpdatedAt?->toDateTimeString(), $catalogProduct->updated_at?->toDateTimeString());
        $this->assertSame($originalVariantUpdatedAt?->toDateTimeString(), $catalogVariant->updated_at?->toDateTimeString());
        $this->assertDatabaseHas('tenant_catalog_products', [
            'tenant_account_id' => $this->tenant->id,
            'standard_product_id' => $missingProduct->id,
        ]);
        $this->assertDatabaseHas('tenant_catalog_product_variants', [
            'tenant_account_id' => $this->tenant->id,
            'standard_product_variant_id' => $missingVariant->id,
        ]);

        CarbonImmutable::setTestNow();
    }

    public function test_standard_product_id_filter_limits_projection_scope(): void
    {
        $first = $this->createStandardProduct('REP-301', 'Scoped One');
        $this->createStandardVariant($first, 'REP-301-SIYAH', 'Siyah');
        $second = $this->createStandardProduct('REP-302', 'Scoped Two');
        $this->createStandardVariant($second, 'REP-302-BEYAZ', 'Beyaz');

        $this->grantSupplierAccess(true, true, true, true);

        $this->artisan('prodelya:project-tenant-catalog', [
            '--tenant' => $this->tenant->slug,
            '--supplier' => $this->supplier->code,
            '--missing-only' => true,
            '--standard-product-id' => [$first->id],
        ])
            ->expectsOutputToContain('Seçilen standard ürün ID sayısı: 1')
            ->expectsOutputToContain('Aday standard ürün: 1')
            ->assertExitCode(0);

        $this->assertDatabaseHas('tenant_catalog_products', [
            'tenant_account_id' => $this->tenant->id,
            'standard_product_id' => $first->id,
        ]);
        $this->assertDatabaseMissing('tenant_catalog_products', [
            'tenant_account_id' => $this->tenant->id,
            'standard_product_id' => $second->id,
        ]);
    }

    public function test_inactive_access_prevents_missing_only_repair(): void
    {
        $product = $this->createStandardProduct('REP-401', 'Blocked Product');
        $this->createStandardVariant($product, 'REP-401-SIYAH', 'Siyah');

        $this->grantSupplierAccess(false, true, true, true);

        $this->artisan('prodelya:project-tenant-catalog', [
            '--tenant' => $this->tenant->slug,
            '--supplier' => $this->supplier->code,
            '--missing-only' => true,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Verilen supplier filtresi ile eşleşen uygun hazır tedarikçi kaynağı bulunamadı.')
            ->assertFailed();

        $this->assertDatabaseCount('tenant_catalog_products', 0);
        $this->assertDatabaseCount('tenant_catalog_product_variants', 0);
    }

    private function createStandardProduct(string $code, string $name): StandardProduct
    {
        return StandardProduct::query()->create([
            'supplier_id' => $this->supplier->id,
            'standard_product_code' => $code,
            'sku' => $code,
            'product_name' => $name,
            'base_product_name' => $name,
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $code)),
            'standard_category_id' => $this->category->id,
            'product_family' => 'promotion',
            'image_url' => 'https://example.test/' . strtolower($code) . '.jpg',
            'currency' => 'TL',
            'min_purchase_price' => 100,
            'max_purchase_price' => 100,
            'total_stock_quantity' => 25,
            'supplier_count' => 1,
            'variant_count' => 0,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $this->supplier->id,
                'supplier_name' => $this->supplier->name,
                'supplier_product_code' => $code,
            ]],
            'meta' => [
                'price_snapshot' => [
                    'list_price' => 100,
                    'vat_rate' => 20,
                ],
            ],
            'is_active' => true,
        ]);
    }

    private function createStandardVariant(StandardProduct $product, string $code, string $name): StandardProductVariant
    {
        $product->forceFill(['variant_count' => $product->variant_count + 1])->save();

        return StandardProductVariant::query()->create([
            'standard_product_id' => $product->id,
            'variant_code' => $code,
            'generated_variant_code' => $code,
            'variant_name' => $name,
            'variant_color' => $name,
            'image_url' => 'https://example.test/' . strtolower($code) . '.jpg',
            'stock_quantity' => 10,
            'min_purchase_price' => 110,
            'max_purchase_price' => 110,
            'supplier_count' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [
                'supplier_id' => $this->supplier->id,
                'supplier_product_code' => $code,
            ],
            'meta' => [
                'price_snapshot' => [
                    'list_price' => 110,
                    'vat_rate' => 20,
                ],
            ],
        ]);
    }

    private function createCatalogProjection(StandardProduct $product): TenantCatalogProduct
    {
        app(\App\Services\ProductDataHub\TenantCatalogProjectionService::class)->projectForTenant($this->tenant, [
            'supplier_ids' => [$this->supplier->id],
            'standard_product_ids' => [$product->id],
        ]);

        return TenantCatalogProduct::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('standard_product_id', $product->id)
            ->firstOrFail();
    }

    private function grantSupplierAccess(bool $isActive, bool $canViewProducts, bool $visibleInCatalog, bool $canUseInQuotes): void
    {
        TenantSupplierAccess::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'supplier_id' => $this->supplier->id,
            ],
            [
                'is_active' => $isActive,
                'can_view_products' => $canViewProducts,
                'visible_in_catalog' => $visibleInCatalog,
                'can_use_in_quotes' => $canUseInQuotes,
                'can_request_purchase' => true,
                'price_multiplier' => 1,
                'safe_stock_quantity' => 0,
                'export_allowed' => false,
            ]
        );
    }
}
