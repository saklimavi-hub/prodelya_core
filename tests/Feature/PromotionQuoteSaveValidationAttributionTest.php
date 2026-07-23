<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantPrintOption;
use App\Models\TenantPrintSetting;
use App\Models\User;
use App\Services\TenantPrintOptionService;
use App\Services\TenantPrintSettingSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteSaveValidationAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        app(TenantPrintSettingSyncService::class)->syncForTenant($this->tenant);
    }

    public function test_store_returns_exact_price_snapshot_error_key_for_unresolved_catalog_item(): void
    {
        $payload = $this->basePayload([
            $this->unresolvedCatalogItemPayload(),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.create'))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), $payload);

        $response->assertRedirect(route('admin.promotion-quotes.create'));
        $response->assertSessionHasErrors([
            'error',
            'items.0.price_snapshot',
        ]);
        $response->assertSessionHasErrors([
            'items.0.price_snapshot' => 'Ürün fiyat özeti okunamadı. Satırı yeniden seçip tekrar deneyin.',
        ]);
    }

    public function test_redirected_create_page_maps_price_snapshot_error_to_summary_row_and_old_input(): void
    {
        $payload = $this->basePayload([
            $this->unresolvedCatalogItemPayload(),
        ]);

        $response = $this->followingRedirects()
            ->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.create'))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), $payload);

        $response->assertOk();
        $response->assertSee('Satır bazında düzeltme gerekiyor.');
        $response->assertSee('Ürün 1 — Ürün fiyat özeti okunamadı. Satırı yeniden seçip tekrar deneyin.');
        $response->assertSee('data-error-target="items.0.price_snapshot"', false);
        $response->assertSee('items.0.price_snapshot', false);
        $response->assertSee($payload['items'][0]['product_name']);
        $response->assertSee($payload['items'][0]['product_code']);
    }

    public function test_store_returns_exact_setup_requirement_key_for_print_row_and_redirected_page_maps_it(): void
    {
        config()->set('prodelya.features.promotion_intermediate_element_enabled', true);
        $setting = TenantPrintSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->whereHas('standardPrintType', fn ($query) => $query->where('code', 'HOT_STAMPING'))
            ->firstOrFail();

        app(TenantPrintOptionService::class)->ensureDefaultsForSetting($setting);

        $option = TenantPrintOption::query()
            ->where('tenant_print_setting_id', $setting->id)
            ->where('code', 'hot-stamping-new-cliche')
            ->firstOrFail();

        $item = [
            'product_name' => 'Setup Validation Ürünü',
            'product_code' => 'SETUP-REQ-001',
            'quantity' => '100',
            'unit' => 'Adet',
            'list_price' => '5',
            'discount_rate' => '0',
            'unit_price' => '5',
            'manual_unit_price' => '1',
            'vat_rate' => '0',
            'has_print' => '1',
            'prints' => [[
                'tenant_print_setting_id' => $setting->id,
                'standard_print_type_id' => $setting->standard_print_type_id,
                'tenant_print_option_id' => $option->id,
                'print_type' => $setting->displayName(),
                'print_option' => $option->name,
                'print_quantity' => '100',
                'base_print_unit_price' => '10',
                'print_unit_price' => '10',
                'setup_type' => 'cliche',
                'setup_pricing_enabled' => '0',
                'setup_status' => '',
                'cliche_status' => '',
            ]],
        ];

        $postResponse = $this->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.create'))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), $this->basePayload([$item]));

        $postResponse->assertRedirect(route('admin.promotion-quotes.create'));
        $postResponse->assertSessionHasErrors([
            'error',
            'items.0.prints.0.setup_requirement',
        ]);
        $postResponse->assertSessionHasErrors([
            'items.0.prints.0.setup_requirement' => 'Bu baskı için ara eleman ayarı gereklidir.',
        ]);

        $pageResponse = $this->followingRedirects()
            ->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.create'))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), $this->basePayload([$item]));

        $pageResponse->assertOk();
        $pageResponse->assertSee('Ürün 1 / Baskı 1a — Bu baskı için ara eleman ayarı gereklidir.');
        $pageResponse->assertSee('data-error-target="items.0.prints.0.setup_requirement"', false);
        $pageResponse->assertSee('items.0.prints.0.setup_requirement', false);
        $pageResponse->assertSee('Bu baskı için ara eleman ayarı gereklidir.');
        $pageResponse->assertSee($option->name);
    }

    private function basePayload(array $items): array
    {
        return [
            'customer_company_id' => $this->customer->id,
            'quote_date' => '2026-07-14',
            'valid_until' => '2026-07-21',
            'invoice_status' => 'fis',
            'currency' => 'TL',
            'delivery_type' => 'Kargo',
            'items' => $items,
        ];
    }

    private function unresolvedCatalogItemPayload(): array
    {
        $product = TenantCatalogProduct::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('visible_in_quote', true)
            ->where('is_active', true)
            ->first();

        if (! $product) {
            $product = TenantCatalogProduct::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'name' => 'Validation Snapshot Ürünü',
                'tenant_sku' => 'VAL-SNAP-001',
                'product_name' => 'Validation Snapshot Ürünü',
                'product_code' => 'VAL-SNAP-001',
                'currency' => 'TRY',
                'display_price' => 0,
                'visible_in_catalog' => true,
                'visible_in_quote' => true,
                'is_active' => true,
                'catalog_source' => 'tenant_catalog',
                'catalog_status' => 'active',
            ]);
        }

        $variant = TenantCatalogProductVariant::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('tenant_catalog_product_id', $product->id)
            ->where('is_active', true)
            ->first();

        if (! $variant) {
            $variant = TenantCatalogProductVariant::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'tenant_catalog_product_id' => $product->id,
                'variant_code' => 'VAL-SNAP-001-V1',
                'variant_name' => 'Test Varyantı',
                'display_price' => 0,
                'currency' => 'TRY',
                'stock_quantity' => 10,
                'local_stock_quantity' => 10,
                'supplier_stock_quantity' => 0,
                'visible_in_catalog' => true,
                'is_active' => true,
            ]);
        }

        $productName = $variant->display_name ?: ($product->product_name ?: $product->name ?: 'Snapshot Test Ürünü');
        $productCode = $variant->variant_code ?: ($product->product_code ?: 'SNAP-TEST-001');

        return [
            'product_name' => $productName,
            'product_code' => $productCode,
            'quantity' => '1',
            'unit' => 'Adet',
            'list_price' => '0.00',
            'discount_rate' => '0',
            'unit_price' => '500.00',
            'manual_unit_price' => '1',
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
                'product_code' => $productCode,
                'product_name' => $productName,
                'is_warning_sellable' => false,
            ],
            'product_snapshot' => [
                'tenant_catalog_product_id' => $product->id,
                'tenant_catalog_product_variant_id' => $variant->id,
                'product_code' => $productCode,
                'product_name' => $productName,
            ],
            'price_snapshot' => [],
            'prints' => [],
        ];
    }
}
