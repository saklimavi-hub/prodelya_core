<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrintSetupRequirement;
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

class PromotionIntermediateElementFeatureFlagTest extends TestCase
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

    public function test_feature_flag_defaults_to_disabled(): void
    {
        $this->assertFalse(config('prodelya.features.promotion_intermediate_element_enabled'));
    }

    public function test_disabled_flag_allows_setup_required_print_to_save_without_setup_validation_and_ignores_setup_fields(): void
    {
        [$setting, $option] = $this->setupRequiredOption();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), [
                'customer_company_id' => $this->customer->id,
                'quote_date' => '2026-07-14',
                'valid_until' => '2026-07-21',
                'invoice_status' => 'fis',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'items' => [[
                    'product_name' => 'Feature Flag Ürünü',
                    'product_code' => 'FLAG-001',
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '5',
                    'discount_rate' => '0',
                    'unit_price' => '5',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
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
                        'setup_pricing_enabled' => '1',
                        'setup_status' => 'Yeni üretilecek',
                        'cliche_status' => 'Yeni üretilecek',
                        'setup_total_amount' => '800',
                    ]],
                ]],
            ]);

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();

        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));
        $response->assertSessionDoesntHaveErrors();

        $print = $quote->items()->with('prints')->firstOrFail()->prints->firstOrFail();
        $this->assertNull($print->setup_type);
        $this->assertNull($print->setup_status);
        $this->assertFalse((bool) $print->setup_pricing_enabled);
        $this->assertNull($print->cliche_status);
        $this->assertSame(0.0, (float) $print->setup_total_amount);
        $this->assertSame(0, OrderItemPrintSetupRequirement::query()->count());
    }

    public function test_disabled_flag_keeps_price_snapshot_validation_active(): void
    {
        $payload = [
            'customer_company_id' => $this->customer->id,
            'quote_date' => '2026-07-14',
            'valid_until' => '2026-07-21',
            'invoice_status' => 'fis',
            'currency' => 'TL',
            'delivery_type' => 'Kargo',
            'items' => [
                $this->unresolvedCatalogItemPayload(),
            ],
        ];

        $response = $this->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.create'))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), $payload);

        $response->assertRedirect(route('admin.promotion-quotes.create'));
        $response->assertSessionHasErrors([
            'error',
            'items.0.price_snapshot',
        ]);
    }

    private function setupRequiredOption(): array
    {
        $setting = TenantPrintSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->whereHas('standardPrintType', fn ($query) => $query->where('code', 'HOT_STAMPING'))
            ->firstOrFail();

        app(TenantPrintOptionService::class)->ensureDefaultsForSetting($setting);

        $option = TenantPrintOption::query()
            ->where('tenant_print_setting_id', $setting->id)
            ->where('code', 'hot-stamping-new-cliche')
            ->firstOrFail();

        return [$setting, $option];
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
