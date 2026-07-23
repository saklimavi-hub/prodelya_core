<?php

namespace Tests\Feature\Concerns;

use App\Models\StandardCategory;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantLocalStock;
use App\Models\TenantSupplierAccess;
use App\Models\User;

trait BuildsLocalProductSourceFixtures
{
    private const CENTRAL_HOST = 'prodelya_core.test';

    protected User $adminUser;
    protected TenantAccount $tenant;
    protected StandardCategory $category;

    protected function setUpLocalProductSourceFixtures(): void
    {
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
    }

    protected function makeSupplierWithAccess(string $code = 'LOCAL-PRODUCT-SUP'): Supplier
    {
        $uniqueCode = $code . '-' . strtoupper(substr((string) uniqid('', true), -6));

        $supplier = Supplier::query()->create([
            'name' => $code . ' Supplier',
            'code' => $uniqueCode,
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
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

        return $supplier;
    }

    protected function makeCatalogProduct(array $overrides = [], ?TenantAccount $tenant = null): TenantCatalogProduct
    {
        $tenant ??= $this->tenant;

        $defaults = [
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => null,
            'tenant_sku' => 'SKU-' . uniqid(),
            'name' => $overrides['product_name'] ?? 'Katalog Ürünü',
            'product_code' => 'CAT-' . uniqid(),
            'product_name' => 'Katalog Ürünü',
            'slug' => 'katalog-urunu-' . uniqid(),
            'standard_category_id' => $this->category->id,
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
        ];

        return TenantCatalogProduct::query()->create(array_merge($defaults, $overrides));
    }

    protected function makeCatalogVariant(TenantCatalogProduct $product, array $overrides = []): TenantCatalogProductVariant
    {
        return TenantCatalogProductVariant::query()->create(array_merge([
            'tenant_account_id' => $product->tenant_account_id,
            'tenant_catalog_product_id' => $product->id,
            'standard_product_variant_id' => null,
            'variant_code' => 'VAR-' . uniqid(),
            'variant_name' => 'Test Varyantı',
            'variant_color' => 'Mavi',
            'variant_size' => null,
            'image_url' => 'https://example.test/variant.jpg',
            'display_price' => 100,
            'currency' => 'TL',
            'stock_quantity' => 50,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 50,
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => $product->source_summary,
            'meta' => ['is_variant' => true, 'is_sellable' => true],
        ], $overrides));
    }

    protected function makeOperationalLocalStock(TenantCatalogProduct $product, float $onHand, ?TenantAccount $tenant = null): TenantLocalStock
    {
        $tenant ??= $this->tenant;

        return TenantLocalStock::query()->create([
            'tenant_account_id' => $tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'tenant_catalog_product_variant_id' => null,
            'stock_scope' => 'product',
            'legacy_assignment_status' => null,
            'warehouse_code' => 'LOCAL-MAIN',
            'location_code' => null,
            'quantity_on_hand' => $onHand,
            'quantity_reserved' => 0,
            'quantity_available' => $onHand,
            'reorder_level' => 0,
            'max_stock' => null,
            'last_counted_at' => now(),
            'notes' => 'Fixture stock row',
        ]);
    }

    protected function makeOperationalLocalVariantStock(TenantCatalogProduct $product, TenantCatalogProductVariant $variant, float $onHand, ?TenantAccount $tenant = null): TenantLocalStock
    {
        $tenant ??= $this->tenant;

        return TenantLocalStock::query()->create([
            'tenant_account_id' => $tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'tenant_catalog_product_variant_id' => $variant->id,
            'stock_scope' => 'variant',
            'legacy_assignment_status' => null,
            'warehouse_code' => 'LOCAL-MAIN',
            'location_code' => null,
            'quantity_on_hand' => $onHand,
            'quantity_reserved' => 0,
            'quantity_available' => $onHand,
            'reorder_level' => 0,
            'max_stock' => null,
            'last_counted_at' => now(),
            'notes' => 'Fixture variant stock row',
        ]);
    }

    protected function makeLegacyUnassignedOperationalStock(TenantCatalogProduct $product, float $onHand, ?TenantAccount $tenant = null): TenantLocalStock
    {
        $tenant ??= $this->tenant;

        return TenantLocalStock::query()->create([
            'tenant_account_id' => $tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'tenant_catalog_product_variant_id' => null,
            'stock_scope' => 'product',
            'legacy_assignment_status' => 'legacy_unassigned',
            'warehouse_code' => 'LOCAL-MAIN',
            'location_code' => null,
            'quantity_on_hand' => $onHand,
            'quantity_reserved' => 0,
            'quantity_available' => $onHand,
            'reorder_level' => 0,
            'max_stock' => null,
            'last_counted_at' => now(),
            'notes' => 'Fixture legacy unassigned stock row',
        ]);
    }

    protected function getOnCentralHost(string $uri)
    {
        return $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get($uri);
    }

    protected function postOnCentralHost(string $uri, array $payload)
    {
        return $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post($uri, $payload);
    }
}
