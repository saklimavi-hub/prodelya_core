<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantCatalogProjectionBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private TenantAccount $tenant;
    private TenantAccount $otherTenant;
    private Supplier $visibleSupplier;
    private Supplier $blockedSupplier;
    private StandardCategory $category;
    private Role $tenantOwnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();

        $this->tenant = $this->createTenant('projection-tenant');
        $this->otherTenant = $this->createTenant('projection-other-tenant');
        $this->visibleSupplier = Supplier::query()->create([
            'name' => 'Etkin Projection',
            'code' => 'ETKIN-PROJ',
            'status' => 'active',
        ]);
        $this->blockedSupplier = Supplier::query()->create([
            'name' => 'Kapali Projection',
            'code' => 'BLOCK-PROJ',
            'status' => 'active',
        ]);
    }

    public function test_dry_run_reports_projection_plan_without_writing_catalog_rows(): void
    {
        $flatProduct = $this->createStandardProduct($this->visibleSupplier, 'ETKIN-FLAT-001', 'Etkin Flat Ürün');
        $groupProduct = $this->createStandardProduct($this->visibleSupplier, 'ETKIN-GROUP-001', 'Etkin Grup Ürün');
        $this->createStandardVariant($groupProduct, 'ETKIN-GROUP-001-SIYAH', 'Siyah');
        $this->createStandardProduct($this->blockedSupplier, 'BLOCK-001', 'Kapalı Ürün');

        $this->grantSupplierAccess($this->tenant, $this->visibleSupplier, true, true, true, true);
        $this->grantSupplierAccess($this->tenant, $this->blockedSupplier, false, true, true, true);

        $rawBefore = StandardProduct::query()->count();
        $catalogBefore = TenantCatalogProduct::query()->count();
        $variantBefore = TenantCatalogProductVariant::query()->count();

        $this->artisan('prodelya:project-tenant-catalog', [
            '--tenant' => $this->tenant->slug,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Abone Firma Hesabı: ' . $this->tenant->name)
            ->expectsOutputToContain('Projection için uygun erişimler: 1')
            ->expectsOutputToContain('Aday standard ürün: 2')
            ->expectsOutputToContain('Aday standard varyant: 1')
            ->expectsOutputToContain('Oluşturulacak katalog ürünü: 2')
            ->expectsOutputToContain('Oluşturulacak katalog varyantı: 1')
            ->expectsOutputToContain('Dry-run: Veri yazılmadı.')
            ->assertExitCode(0);

        $this->assertSame($rawBefore, StandardProduct::query()->count());
        $this->assertSame($catalogBefore, TenantCatalogProduct::query()->count());
        $this->assertSame($variantBefore, TenantCatalogProductVariant::query()->count());
        $this->assertDatabaseMissing('tenant_catalog_products', [
            'tenant_account_id' => $this->tenant->id,
            'standard_product_id' => $flatProduct->id,
        ]);
    }

    public function test_command_projects_only_target_tenant_and_is_idempotent(): void
    {
        $flatProduct = $this->createStandardProduct($this->visibleSupplier, 'ETKIN-FLAT-002', 'Etkin Teklif Ürünü');
        $groupProduct = $this->createStandardProduct($this->visibleSupplier, 'ETKIN-GROUP-002', 'Etkin Varyantlı Ürün');
        $variant = $this->createStandardVariant($groupProduct, 'ETKIN-GROUP-002-MAVI', 'Mavi');
        $blockedProduct = $this->createStandardProduct($this->blockedSupplier, 'BLOCK-002', 'Bloklu Ürün');

        $this->grantSupplierAccess($this->tenant, $this->visibleSupplier, true, true, true, true);
        $this->grantSupplierAccess($this->tenant, $this->blockedSupplier, true, false, true, true);
        $this->grantSupplierAccess($this->otherTenant, $this->visibleSupplier, false, true, true, true);

        $this->artisan('prodelya:project-tenant-catalog', [
            '--tenant' => $this->tenant->slug,
        ])
            ->expectsOutputToContain('Projection tamamlandı.')
            ->expectsOutputToContain('Yeni oluşturulan katalog ürünü: 2')
            ->assertExitCode(0);

        $flatCatalog = TenantCatalogProduct::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('standard_product_id', $flatProduct->id)
            ->firstOrFail();
        $groupCatalog = TenantCatalogProduct::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('standard_product_id', $groupProduct->id)
            ->firstOrFail();

        $this->assertTrue($flatCatalog->visible_in_catalog);
        $this->assertTrue($flatCatalog->visible_in_quote);
        $this->assertFalse($groupCatalog->visible_in_quote);
        $this->assertDatabaseHas('tenant_catalog_product_variants', [
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $groupCatalog->id,
            'standard_product_variant_id' => $variant->id,
        ]);
        $this->assertDatabaseMissing('tenant_catalog_products', [
            'tenant_account_id' => $this->tenant->id,
            'standard_product_id' => $blockedProduct->id,
        ]);
        $this->assertDatabaseMissing('tenant_catalog_products', [
            'tenant_account_id' => $this->otherTenant->id,
            'standard_product_id' => $flatProduct->id,
        ]);

        $productCountAfterFirstRun = TenantCatalogProduct::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->count();
        $variantCountAfterFirstRun = TenantCatalogProductVariant::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->count();

        $this->artisan('prodelya:project-tenant-catalog', [
            '--tenant' => $this->tenant->slug,
        ])->assertExitCode(0);

        $this->assertSame($productCountAfterFirstRun, TenantCatalogProduct::query()->where('tenant_account_id', $this->tenant->id)->count());
        $this->assertSame($variantCountAfterFirstRun, TenantCatalogProductVariant::query()->where('tenant_account_id', $this->tenant->id)->count());
    }

    private function createTenant(string $subdomain): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => 'Projection Tenant ' . $subdomain,
            'legal_name' => 'Projection Tenant ' . $subdomain,
            'slug' => $subdomain,
            'panel_subdomain' => $subdomain,
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function createStandardProduct(Supplier $supplier, string $code, string $name): StandardProduct
    {
        return StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
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
            'max_purchase_price' => 120,
            'total_stock_quantity' => 250,
            'supplier_count' => 1,
            'variant_count' => 0,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
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
        $product->forceFill(['variant_count' => 1])->save();

        return StandardProductVariant::query()->create([
            'standard_product_id' => $product->id,
            'variant_code' => $code,
            'generated_variant_code' => $code,
            'variant_name' => $name,
            'variant_color' => $name,
            'image_url' => 'https://example.test/' . strtolower($code) . '.jpg',
            'stock_quantity' => 75,
            'min_purchase_price' => 125,
            'max_purchase_price' => 140,
            'supplier_count' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [
                'supplier_id' => $product->supplier_id,
                'supplier_product_code' => $code,
            ],
            'meta' => [
                'price_snapshot' => [
                    'list_price' => 125,
                    'vat_rate' => 20,
                ],
                'variant_attributes' => [
                    'option' => $name,
                ],
            ],
        ]);
    }

    private function grantSupplierAccess(
        TenantAccount $tenant,
        Supplier $supplier,
        bool $isActive,
        bool $canViewProducts,
        bool $visibleInCatalog,
        bool $canUseInQuotes
    ): void {
        TenantSupplierAccess::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'supplier_id' => $supplier->id,
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
