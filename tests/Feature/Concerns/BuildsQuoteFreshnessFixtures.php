<?php

namespace Tests\Feature\Concerns;

use App\Models\Company;
use App\Models\Role;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;

trait BuildsQuoteFreshnessFixtures
{
    private const CENTRAL_HOST = 'prodelya_core.test';

    protected function createQuoteFreshnessFixture(string $suffix = 'main', array $overrides = []): array
    {
        $role = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();

        $tenant = TenantAccount::query()->create([
            'name' => 'Quote Freshness Tenant ' . $suffix,
            'legal_name' => 'Quote Freshness Tenant ' . $suffix,
            'slug' => 'quote-freshness-' . $suffix . '-' . uniqid(),
            'panel_subdomain' => 'quote-freshness-' . $suffix . '-' . uniqid(),
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $user = User::query()->create([
            'name' => 'Quote Freshness User ' . $suffix,
            'email' => 'quote-freshness-' . $suffix . '-' . uniqid() . '@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        $customer = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'name' => 'Freshness Musteri ' . $suffix,
            'legal_name' => 'Freshness Musteri ' . $suffix,
            'company_type' => 'customer',
            'is_customer' => true,
            'status' => 'active',
            'email' => 'customer-' . $suffix . '-' . uniqid() . '@example.test',
            'phone' => '5550000000',
            'country' => 'TR',
            'city' => 'Istanbul',
            'district' => 'Sisli',
            'address' => 'Test Mahallesi 1',
        ]);

        $customer->companyRoles()->create([
            'tenant_account_id' => $tenant->id,
            'role_key' => 'customer',
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Quote Freshness Supplier ' . $suffix,
            'code' => 'QF-' . strtoupper(substr(uniqid(), -6)),
            'status' => 'active',
        ]);

        $productCurrency = strtoupper((string) ($overrides['product_currency'] ?? 'TRY'));
        $variantCurrency = strtoupper((string) ($overrides['variant_currency'] ?? $productCurrency));
        $quoteCurrency = strtoupper((string) ($overrides['quote_currency'] ?? 'TRY'));
        $sourcePrice = (float) ($overrides['source_price'] ?? ($variantCurrency === 'USD' ? 3.5 : 134.0));
        $basePrice = (float) ($overrides['base_price'] ?? ($variantCurrency === 'USD' ? 164.12 : ($overrides['variant_display_price'] ?? $overrides['product_display_price'] ?? 134.0)));
        $appliedRate = (float) ($overrides['applied_rate'] ?? ($variantCurrency === 'USD' ? 46.8914 : 1.0));
        $quotePriceStatus = $variantCurrency === 'USD' ? 'ready' : 'not_required';

        $currencySnapshot = array_merge([
            'source_price' => $sourcePrice,
            'source_currency' => $variantCurrency,
            'base_price' => $basePrice,
            'base_currency' => 'TRY',
            'conversion_available' => true,
            'conversion_status' => $quotePriceStatus,
            'applied_rate' => $appliedRate,
            'rate_date' => '2026-07-10',
            'rate_source' => 'tcmb',
            'rate_type' => 'forex_selling',
            'is_fallback_rate' => false,
            'is_stale_rate' => false,
            'currency_origin' => 'product_field',
            'currency_status' => 'resolved',
        ], $overrides['currency_snapshot'] ?? []);

        $standardProduct = StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => $overrides['standard_product_code'] ?? ('QF-' . strtoupper(substr($suffix, 0, 3)) . '-P'),
            'sku' => $overrides['standard_product_code'] ?? ('QF-' . strtoupper(substr($suffix, 0, 3)) . '-P'),
            'product_name' => $overrides['product_name'] ?? 'Quote Freshness Urunu',
            'base_product_name' => $overrides['product_name'] ?? 'Quote Freshness Urunu',
            'name' => $overrides['product_name'] ?? 'Quote Freshness Urunu',
            'slug' => 'quote-freshness-product-' . uniqid(),
            'standard_category_id' => $category->id,
            'product_family' => 'promotion',
            'currency' => $productCurrency,
            'min_purchase_price' => (float) ($overrides['standard_product_price'] ?? $basePrice),
            'max_purchase_price' => (float) ($overrides['standard_product_price'] ?? $basePrice),
            'total_stock_quantity' => (float) ($overrides['standard_product_stock'] ?? 500),
            'supplier_count' => 1,
            'variant_count' => 1,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'supplier_product_code' => $overrides['product_code'] ?? 'EL-KOD-35',
            ]],
            'meta' => [
                'price_snapshot' => [
                    'list_price' => (float) ($overrides['standard_product_price'] ?? $basePrice),
                    'source_price' => $sourcePrice,
                    'source_currency' => $variantCurrency,
                    'currency_snapshot' => $currencySnapshot,
                ],
            ],
            'is_active' => true,
        ]);

        $standardVariant = StandardProductVariant::query()->create([
            'standard_product_id' => $standardProduct->id,
            'tenant_account_id' => $tenant->id,
            'variant_code' => $overrides['variant_code'] ?? 'PZ-CH60SY',
            'generated_variant_code' => $overrides['variant_code'] ?? 'PZ-CH60SY',
            'variant_name' => $overrides['variant_name'] ?? 'Exact Variant',
            'variant_color' => $overrides['variant_color'] ?? 'Mavi',
            'stock_quantity' => (float) ($overrides['standard_variant_stock'] ?? 6500),
            'min_purchase_price' => (float) ($overrides['standard_variant_price'] ?? $basePrice),
            'max_purchase_price' => (float) ($overrides['standard_variant_price'] ?? $basePrice),
            'supplier_count' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [
                'supplier_id' => $supplier->id,
                'variant_stock_code' => $overrides['variant_code'] ?? 'PZ-CH60SY',
            ],
            'meta' => [
                'price_snapshot' => [
                    'list_price' => (float) ($overrides['standard_variant_price'] ?? $basePrice),
                    'source_price' => $sourcePrice,
                    'source_currency' => $variantCurrency,
                    'currency_snapshot' => $currencySnapshot,
                ],
            ],
        ]);

        if (isset($overrides['standard_updated_at'])) {
            $standardProduct->forceFill(['updated_at' => $overrides['standard_updated_at']])->save();
            $standardVariant->forceFill(['updated_at' => $overrides['standard_updated_at']])->save();
        }

        $product = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $standardProduct->id,
            'tenant_sku' => 'TEN-QF-' . strtoupper(substr(uniqid(), -6)),
            'name' => $overrides['product_name'] ?? 'Quote Freshness Urunu',
            'product_code' => $overrides['product_code'] ?? 'EL-KOD-35',
            'product_name' => $overrides['product_name'] ?? 'Quote Freshness Urunu',
            'slug' => 'quote-freshness-tenant-product-' . uniqid(),
            'standard_category_id' => $category->id,
            'product_family' => 'promotion',
            'display_price' => (float) ($overrides['product_display_price'] ?? $basePrice),
            'sale_price' => (float) ($overrides['product_display_price'] ?? $basePrice),
            'currency' => $productCurrency,
            'total_stock_quantity' => (float) ($overrides['product_total_stock'] ?? 500),
            'local_stock_quantity' => (float) ($overrides['product_local_stock'] ?? 0),
            'supplier_stock_quantity' => (float) ($overrides['product_supplier_stock'] ?? ($overrides['product_total_stock'] ?? 500)),
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'supplier_product_code' => $overrides['product_code'] ?? 'EL-KOD-35',
            ]],
            'visible_in_catalog' => true,
            'visible_in_quote' => $overrides['visible_in_quote'] ?? true,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'last_synced_at' => $overrides['last_synced_at'] ?? now(),
            'meta' => [
                'price_snapshot' => array_merge([
                    'list_price' => (float) ($overrides['product_display_price'] ?? $basePrice),
                    'source_price' => $sourcePrice,
                    'source_currency' => $productCurrency,
                    'currency' => $productCurrency,
                    'currency_snapshot' => $currencySnapshot,
                ], $overrides['product_price_snapshot'] ?? []),
                'is_parent' => false,
                'is_sellable' => true,
            ],
            'is_active' => $overrides['product_active'] ?? true,
            'stock_quantity' => (float) ($overrides['product_total_stock'] ?? 500),
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
        ]);

        $variant = TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'standard_product_variant_id' => $standardVariant->id,
            'variant_code' => $overrides['variant_code'] ?? 'PZ-CH60SY',
            'variant_name' => $overrides['variant_name'] ?? 'Exact Variant',
            'variant_color' => $overrides['variant_color'] ?? 'Mavi',
            'display_price' => (float) ($overrides['variant_display_price'] ?? $basePrice),
            'currency' => $variantCurrency,
            'stock_quantity' => (float) ($overrides['variant_total_stock'] ?? 6500),
            'local_stock_quantity' => (float) ($overrides['variant_local_stock'] ?? 0),
            'supplier_stock_quantity' => (float) ($overrides['variant_supplier_stock'] ?? ($overrides['variant_total_stock'] ?? 6500)),
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'is_active' => $overrides['variant_active'] ?? true,
            'source_summary' => [
                'supplier_id' => $supplier->id,
                'supplier_product_code' => $overrides['variant_code'] ?? 'PZ-CH60SY',
                'variant_stock_code' => $overrides['variant_code'] ?? 'PZ-CH60SY',
            ],
            'meta' => [
                'quote_search_visible' => $overrides['variant_quote_visible'] ?? true,
                'price_snapshot' => array_merge([
                    'list_price' => (float) ($overrides['variant_display_price'] ?? $basePrice),
                    'source_price' => $sourcePrice,
                    'source_currency' => $variantCurrency,
                    'currency' => $variantCurrency,
                    'currency_snapshot' => $currencySnapshot,
                ], $overrides['variant_price_snapshot'] ?? []),
            ],
        ]);

        if (isset($overrides['projection_updated_at'])) {
            $product->forceFill(['updated_at' => $overrides['projection_updated_at'], 'last_synced_at' => $overrides['projection_updated_at']])->save();
            $variant->forceFill(['updated_at' => $overrides['projection_updated_at']])->save();
        }

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

        return compact('tenant', 'user', 'customer', 'supplier', 'product', 'variant', 'standardProduct', 'standardVariant', 'quoteCurrency', 'basePrice', 'sourcePrice', 'appliedRate');
    }

    protected function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }

    protected function buildQuoteStorePayload(array $fixture, array $itemOverrides = []): array
    {
        $variant = $fixture['variant'];
        $product = $fixture['product'];
        $quoteCurrency = $fixture['quoteCurrency'] ?? 'TRY';
        $displayPrice = (float) ($itemOverrides['quote_price_value'] ?? ($variant->currency === 'USD' ? ($fixture['basePrice'] ?? $variant->display_price) : $variant->display_price));
        $sourcePrice = (float) ($fixture['sourcePrice'] ?? data_get($variant->meta, 'price_snapshot.source_price', $variant->display_price));
        $sourceCurrency = data_get($variant->meta, 'price_snapshot.source_currency', $variant->currency);
        $appliedRate = (float) ($fixture['appliedRate'] ?? data_get($variant->meta, 'price_snapshot.currency_snapshot.applied_rate', 1));

        $item = array_merge([
            'product_name' => $variant->display_name ?: $product->display_name,
            'product_code' => $variant->variant_code,
            'quantity' => '10',
            'unit' => 'Adet',
            'list_price' => (string) $displayPrice,
            'discount_rate' => '0',
            'unit_price' => (string) $displayPrice,
            'manual_unit_price' => '0',
            'vat_rate' => '20',
            'has_print' => '0',
            'tenant_catalog_product_id' => $product->id,
            'tenant_catalog_product_variant_id' => $variant->id,
            'catalog_source' => 'tenant_catalog',
            'selected_catalog_identity' => [
                'catalog_source' => 'tenant_catalog',
                'tenant_catalog_product_id' => $product->id,
                'tenant_catalog_product_variant_id' => $variant->id,
                'standard_product_id' => $product->standard_product_id,
                'standard_product_variant_id' => $variant->standard_product_variant_id,
                'product_code' => $variant->variant_code,
                'product_name' => $variant->display_name ?: $product->display_name,
                'is_warning_sellable' => false,
            ],
            'product_snapshot' => [
                'tenant_catalog_product_id' => $product->id,
                'tenant_catalog_product_variant_id' => $variant->id,
                'standard_product_id' => $product->standard_product_id,
                'standard_product_variant_id' => $variant->standard_product_variant_id,
                'product_code' => $variant->variant_code,
                'product_name' => $variant->display_name ?: $product->display_name,
            ],
            'price_snapshot' => [
                'list_price' => $displayPrice,
                'display_price' => $displayPrice,
                'currency' => $quoteCurrency,
                'source_price' => $sourcePrice,
                'source_currency' => $sourceCurrency,
                'currency_snapshot' => data_get($variant->meta, 'price_snapshot.currency_snapshot', []),
                'quote_price_value' => $displayPrice,
                'quote_currency' => $quoteCurrency,
                'quote_price_status' => $sourceCurrency === 'USD' ? 'ready' : 'not_required',
                'quote_price_snapshot' => [
                    'document_currency' => $quoteCurrency,
                    'suggested_sales_unit_price_document' => $displayPrice,
                    'actual_sales_unit_price_document' => $displayPrice,
                    'manual_sales_price_override' => false,
                    'document_conversion_status' => $sourceCurrency === 'USD' ? 'converted' : 'not_required',
                    'applied_rate' => $appliedRate,
                    'rate_date' => '2026-07-10',
                    'rate_source' => 'tcmb',
                    'rate_type' => 'forex_selling',
                    'source_price' => $sourcePrice,
                    'source_currency' => $sourceCurrency,
                ],
                'vat_rate' => 20,
            ],
            'stock_snapshot' => [
                'visible_stock_quantity' => (float) $variant->stock_quantity,
                'supplier_stock_quantity' => (float) $variant->supplier_stock_quantity,
                'local_stock_quantity' => (float) $variant->local_stock_quantity,
            ],
            'prints' => [],
        ], $itemOverrides);

        return [
            'customer_company_id' => $fixture['customer']->id,
            'quote_date' => '2026-07-15',
            'valid_until' => '2026-07-22',
            'invoice_status' => 'fis',
            'currency' => $quoteCurrency,
            'delivery_type' => 'Kargo',
            'items' => [$item],
        ];
    }
}
