<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CatalogSearchController;
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
use App\Services\ProductDataHub\StandardProductBuilderService;
use App\Services\ProductDataHub\TenantCatalogProjectionService;
use App\Services\TenantCatalog\TenantCatalogListRowQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class SuperAdminCatalogOutputProjectionActionsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->actingAs($this->adminUser);
    }

    public function test_catalog_output_refresh_updates_stale_projection_and_clears_projection_outdated_badge(): void
    {
        [$tenant, $standardProduct, $standardVariant, $catalogProduct, $catalogVariant] = $this->createStaleProjectionFixture();

        $before = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.product-panel', ['search' => '0506-L']));

        $before->assertOk();
        $before->assertSee('Katalog Fiyatı Eski');
        $before->assertSee('Kategori Bekliyor');

        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.product-data-hub.catalog-output.project-refresh'), [
                'tenant_id' => $tenant->id,
            ]);

        $response->assertRedirect(route('admin.super.product-data-hub.catalog-output', ['tenant_id' => $tenant->id]));
        $response->assertSessionHas('success');

        $catalogProduct->refresh();
        $catalogVariant->refresh();
        $standardVariant->refresh();

        $this->assertSame(9.2, round((float) $catalogProduct->display_price, 2));
        $this->assertSame(9.2, round((float) $catalogVariant->display_price, 2));
        $this->assertSame(27800.0, (float) $catalogVariant->stock_quantity);
        $this->assertSame(27800.0, (float) $catalogVariant->supplier_stock_quantity);
        $this->assertSame(9.2, round((float) $standardVariant->min_purchase_price, 2));

        $catalogRows = app(TenantCatalogListRowQueryService::class)
            ->paginate($tenant, ['search' => '0506-L'], Request::create('/admin/catalog', 'GET'), 'products');
        $catalogRow = collect($catalogRows->items())->first(fn ($item) => ($item->product_code ?? null) === 'ET-0506-L');

        $this->assertNotNull($catalogRow);
        $this->assertSame(9.2, round((float) ($catalogRow->display_price ?? 0), 2));
        $this->assertSame(27800.0, (float) ($catalogRow->effective_stock_quantity ?? 0));

        $request = Request::create('/admin/catalog/search', 'GET', ['q' => '0506-L']);
        $request->attributes->set('current_tenant', $tenant);
        /** @var JsonResponse $quoteSearch */
        $quoteSearch = app(CatalogSearchController::class)->search($request);
        $results = $quoteSearch->getData(true);

        $this->assertTrue(collect($results)->contains(fn (array $item) =>
            ($item['product_code'] ?? null) === 'ET-0506-L'
            && round((float) ($item['display_price'] ?? 0), 2) === 9.2
            && (float) ($item['visible_stock_quantity'] ?? 0) === 27800.0
        ));

        $after = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.product-panel', ['search' => '0506-L']));

        $after->assertOk();
        $after->assertDontSee('Katalog Fiyatı Eski');
        $after->assertDontSee('Katalog Stoğu Eski');
        $after->assertSee('Kategori Bekliyor');
    }

    public function test_catalog_output_project_missing_creates_missing_projection_rows(): void
    {
        [$tenant, $standardProduct, $standardVariant] = $this->createMissingProjectionFixture();

        $this->assertDatabaseMissing('tenant_catalog_products', [
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $standardProduct->id,
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.product-data-hub.catalog-output.project-missing'), [
                'tenant_id' => $tenant->id,
            ]);

        $response->assertRedirect(route('admin.super.product-data-hub.catalog-output', ['tenant_id' => $tenant->id]));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('tenant_catalog_products', [
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $standardProduct->id,
        ]);
        $this->assertDatabaseHas('tenant_catalog_product_variants', [
            'tenant_account_id' => $tenant->id,
            'standard_product_variant_id' => $standardVariant->id,
        ]);
    }

    public function test_catalog_output_refresh_requires_explicit_tenant_context_and_does_not_mutate_without_it(): void
    {
        [, , , $catalogProduct, $catalogVariant] = $this->createStaleProjectionFixture();

        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.product-data-hub.catalog-output.project-refresh'));

        $response->assertRedirect(route('admin.super.product-data-hub.catalog-output'));
        $response->assertSessionHas('error', 'Abone Firma seçilmeden katalog yansıtma güncellemesi çalıştırılamaz.');

        $catalogProduct->refresh();
        $catalogVariant->refresh();

        $this->assertSame(6.5, round((float) $catalogProduct->display_price, 2));
        $this->assertSame(6.5, round((float) $catalogVariant->display_price, 2));
        $this->assertSame(13600.0, (float) $catalogVariant->stock_quantity);
    }

    private function createStaleProjectionFixture(): array
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Saklimavi Projection Test',
            'legal_name' => 'Saklimavi Projection Test Ltd.',
            'slug' => 'saklimavi-projection-test',
            'panel_subdomain' => 'saklimavi-projection-test',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
        ]);

        [$source, $standardProduct, $standardVariant] = $this->createEtkinProductFixture();

        $this->grantAccess($tenant, $source->supplier_id);

        app(TenantCatalogProjectionService::class)->projectForTenant($tenant, [
            'supplier_ids' => [$source->supplier_id],
            'standard_product_ids' => [$standardProduct->id],
        ]);

        $catalogProduct = TenantCatalogProduct::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('standard_product_id', $standardProduct->id)
            ->firstOrFail();
        $catalogVariant = TenantCatalogProductVariant::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('standard_product_variant_id', $standardVariant->id)
            ->firstOrFail();

        $catalogProduct->forceFill([
            'display_price' => 6.5,
            'sale_price' => 6.5,
            'meta' => array_merge($catalogProduct->meta ?? [], [
                'category_missing_warning' => true,
                'projection_status' => 'category_pending',
            ]),
        ])->save();

        $catalogVariant->forceFill([
            'display_price' => 6.5,
            'stock_quantity' => 13600,
            'supplier_stock_quantity' => 13600,
            'meta' => array_merge($catalogVariant->meta ?? [], [
                'category_missing_warning' => true,
            ]),
        ])->save();

        return [$tenant, $standardProduct, $standardVariant, $catalogProduct, $catalogVariant];
    }

    private function createMissingProjectionFixture(): array
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Projection Missing Test',
            'legal_name' => 'Projection Missing Test Ltd.',
            'slug' => 'projection-missing-test',
            'panel_subdomain' => 'projection-missing-test',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
        ]);

        [$source, $standardProduct, $standardVariant] = $this->createEtkinProductFixture('0506-S', 'Siyah');

        $this->grantAccess($tenant, $source->supplier_id);

        return [$tenant, $standardProduct, $standardVariant];
    }

    private function createEtkinProductFixture(string $variantCode = '0506-L', string $color = 'Lacivert'): array
    {
        $source = $this->makeEtkinSource();
        $rawProduct = $this->createEtkinRawProduct($source, [
            'supplier_product_id' => 4516,
            'supplier_product_code' => $variantCode,
            'supplier_group_code' => '0506',
            'product_name' => 'Plastik Kalem',
            'supplier_category_name' => 'Plastik Kalemler',
            'image_url' => 'https://example.test/0506-parent.jpg',
            'stock_quantity' => 27800,
            'purchase_price' => 9.2,
            'currency' => 'TL',
            'source_price' => 9.2,
            'source_stock' => 27800,
            'normalized_payload' => [
                'list_price' => 9.2,
                'purchase_price' => 9.2,
                'currency' => 'TL',
                'pricing_policy_type' => 'list_price_only',
                'stock_quantity' => 27800,
                'total_variant_stock_quantity' => 27800,
            ],
            'import_hash' => 'et-' . strtolower($variantCode) . '-parent',
        ]);

        $rawVariant = $this->createEtkinRawVariant($source, $rawProduct, [
            'parent_supplier_product_id' => 4516,
            'supplier_group_code' => '0506',
            'variant_code' => $variantCode,
            'variant_stock_code' => $variantCode,
            'generated_variant_code' => 'ET-' . $variantCode,
            'variant_name' => $variantCode . ' Plastik Kalem',
            'variant_color' => $color,
            'variant_stock_quantity' => 27800,
            'normalized_payload' => [
                'list_price' => 9.2,
                'purchase_price' => 9.2,
                'currency' => 'TL',
                'pricing_policy_type' => 'list_price_only',
                'variant_stock_quantity' => 27800,
            ],
            'import_hash' => 'et-' . strtolower($variantCode),
        ]);

        $standardProduct = $this->buildStandardProductFromRaw($rawProduct);
        $standardVariant = StandardProductVariant::query()
            ->where('standard_product_id', $standardProduct->id)
            ->where('generated_variant_code', 'ET-' . $variantCode)
            ->firstOrFail();

        $rawVariant->forceFill([
            'standard_product_variant_id' => $standardVariant->id,
        ])->save();

        return [$source, $standardProduct, $standardVariant];
    }

    private function makeEtkinSource(): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'Etkin Projection Supplier',
            'code' => 'ETKIN-' . uniqid(),
            'status' => 'active',
        ]);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'api',
            'source_name' => 'Etkin Projection Source',
            'status' => 'active',
            'config' => [
                'format' => 'json',
                'profile_key' => 'ETKIN',
                'source_profile_template' => 'ETKIN',
                'sync_policy' => ['sync_frequency' => 'daily'],
            ],
        ]);
    }

    private function createEtkinRawProduct(SupplierSource $source, array $attributes): SupplierProductRaw
    {
        return SupplierProductRaw::query()->create(array_merge([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_product_id' => (string) ($attributes['supplier_product_id'] ?? $attributes['supplier_product_code'] ?? 'ETKIN-RAW'),
            'source_name' => $attributes['product_name'] ?? 'Etkin Product',
            'source_category' => $attributes['supplier_category_name'] ?? 'Plastik Kalemler',
            'source_price' => $attributes['source_price'] ?? $attributes['purchase_price'] ?? null,
            'source_currency' => $attributes['currency'] ?? 'TL',
            'source_stock' => $attributes['source_stock'] ?? $attributes['stock_quantity'] ?? null,
            'sync_status' => 'processed',
        ], $attributes));
    }

    private function createEtkinRawVariant(SupplierSource $source, SupplierProductRaw $rawProduct, array $attributes): SupplierProductVariantRaw
    {
        return SupplierProductVariantRaw::query()->create(array_merge([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'supplier_product_raw_id' => $rawProduct->id,
            'supplier_product_id' => $rawProduct->supplier_product_id,
            'sync_status' => 'processed',
            'variant_attributes' => [],
        ], $attributes));
    }

    private function buildStandardProductFromRaw(SupplierProductRaw $raw): StandardProduct
    {
        $service = app(StandardProductBuilderService::class);
        $service->buildFromRawProduct($raw);

        return StandardProduct::query()->findOrFail($raw->fresh()->standard_product_id);
    }

    private function grantAccess(TenantAccount $tenant, int $supplierId): void
    {
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplierId,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
            'export_allowed' => true,
            'price_multiplier' => 1,
            'safe_stock_quantity' => 0,
        ]);
    }
}
