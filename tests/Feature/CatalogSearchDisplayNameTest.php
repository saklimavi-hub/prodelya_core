<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CatalogSearchController;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CatalogSearchDisplayNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_search_returns_clean_display_name_and_sanitized_source_summary(): void
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Demo Tenant',
            'legal_name' => 'Demo Tenant A.S.',
            'slug' => 'demo-tenant-search-one',
            'panel_subdomain' => 'demo-search-one',
            'status' => 'active',
            'package_key' => 'promotion',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $supplier = Supplier::query()->updateOrCreate([
            'code' => 'AKDENIZ-CATALOG-SEARCH-ONE',
        ], [
            'name' => 'Akdeniz Promosyon',
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'visible_in_catalog' => true,
            'can_use_in_quotes' => true,
        ]);

        $product = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => null,
            'tenant_sku' => 'AK-3008-11-KIRMIZI',
            'name' => 'Kırmızı 11 Fonksiyonlu Çakı 11 Fonksiyonlu Çakı Kırmızı',
            'product_code' => 'AK-3008-11-KIRMIZI',
            'product_name' => 'Kırmızı 11 Fonksiyonlu Çakı 11 Fonksiyonlu Çakı Kırmızı',
            'currency' => 'TL',
            'stock_quantity' => 42,
            'is_active' => true,
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
            'display_price' => 99.90,
            'total_stock_quantity' => 42,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 42,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => 'Akdeniz Promosyon',
                'supplier_source_id' => 77,
                'supplier_group_code' => '3008-11',
                'supplier_product_code' => '3008-11',
                'vat_rate' => 20,
            ]],
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'hidden_reason' => null,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'active',
            'meta' => [],
        ]);

        TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'standard_product_variant_id' => null,
            'variant_code' => 'AK-3008-11-KIRMIZI',
            'variant_name' => 'Kırmızı 11 Fonksiyonlu Çakı 11 Fonksiyonlu Çakı Kırmızı',
            'variant_color' => 'Kırmızı',
            'display_price' => 99.90,
            'currency' => 'TL',
            'stock_quantity' => 42,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 42,
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => 'Akdeniz Promosyon',
                'supplier_source_id' => 77,
                'supplier_group_code' => '3008-11',
                'variant_stock_code' => 'AK-3008-11-KIRMIZI',
            ]],
            'meta' => [
                'parent_product_name' => 'Kırmızı 11 Fonksiyonlu Çakı 11 Fonksiyonlu Çakı Kırmızı',
                'variant_name' => 'Kırmızı 11 Fonksiyonlu Çakı 11 Fonksiyonlu Çakı Kırmızı',
                'variant_attributes' => [],
            ],
        ]);

        $request = Request::create('/admin/catalog/search', 'GET', [
            'q' => 'AK-3008-11-KIRMIZI',
        ]);
        $request->attributes->set('current_tenant', $tenant);

        $response = app(CatalogSearchController::class)->search($request);
        $payload = $response->getData(true);

        $this->assertNotEmpty($payload);
        $this->assertSame('AK-3008-11 Kırmızı 11 Fonksiyonlu Çakı', $payload[0]['product_name']);
        $this->assertSame('AK-3008-11-KIRMIZI', $payload[0]['product_code']);
        $this->assertSame('Akdeniz Promosyon', $payload[0]['supplier_name']);
        $this->assertSame(42.0, (float) $payload[0]['visible_stock_quantity']);
        $this->assertSame(99.9, (float) $payload[0]['list_price']);
        $this->assertArrayNotHasKey('supplier_group_code', $payload[0]['source_summary'][0]);
    }

    public function test_catalog_search_keeps_group_code_searchable_without_leaking_it_as_standalone_name_token(): void
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Demo Tenant',
            'legal_name' => 'Demo Tenant A.S.',
            'slug' => 'demo-tenant-search-two',
            'panel_subdomain' => 'demo-search-two',
            'status' => 'active',
            'package_key' => 'promotion',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $supplier = Supplier::query()->updateOrCreate([
            'code' => 'ETKIN-CATALOG-SEARCH-TWO',
        ], [
            'name' => 'Etkin Promosyon',
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'visible_in_catalog' => true,
            'can_use_in_quotes' => true,
        ]);

        TenantCatalogProduct::query()->create([
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => null,
            'tenant_sku' => 'ET-0506-K',
            'name' => '0506 Plastik Kalem',
            'product_code' => 'ET-0506-K',
            'product_name' => '0506 Plastik Kalem',
            'currency' => 'TL',
            'stock_quantity' => 10,
            'is_active' => true,
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
            'display_price' => 12.50,
            'total_stock_quantity' => 10,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 10,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => 'Etkin Promosyon',
                'supplier_source_id' => 15,
                'supplier_group_code' => '0506',
                'supplier_product_code' => '0506',
            ]],
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'hidden_reason' => null,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'active',
            'meta' => [
                'variant_color' => 'Kırmızı',
            ],
        ]);

        $request = Request::create('/admin/catalog/search', 'GET', [
            'q' => '0506',
        ]);
        $request->attributes->set('current_tenant', $tenant);

        $response = app(CatalogSearchController::class)->search($request);
        $payload = $response->getData(true);

        $this->assertNotEmpty($payload);
        $this->assertSame('ET-0506-K Plastik Kalem', $payload[0]['product_name']);
        $this->assertStringNotContainsString(' 0506 ', ' ' . $payload[0]['product_name'] . ' ');
    }
}
