<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductHubLiveProductInfoEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private Role $tenantOwnerRole;
    private StandardCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
    }

    public function test_tenant_user_can_read_live_info_for_own_catalog_product(): void
    {
        $fixture = $this->makeFixture();

        $response = $this->actingAs($fixture['user'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_id=' . $fixture['product']->id));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'product_name' => $fixture['product']->display_name,
                'variant_name' => null,
                'display_code' => $fixture['product']->display_code,
                'current_stock' => 13600.0,
                'current_price_value' => 6.5,
                'currency' => 'TL',
                'quote_currency' => 'TRY',
                'quote_price_status' => 'not_required',
                'supplier_access_active' => true,
                'tenant_catalog_active' => true,
                'quote_visible' => true,
                'price_changed_since_snapshot' => false,
                'stock_changed_since_snapshot' => false,
                'public_safe_message' => 'Ürün güncel ve teklif için uygun.',
            ]);

        $response->assertJsonMissing(['public_safe_message' => 'Urun guncel ve teklif icin uygun.']);
    }

    public function test_endpoint_returns_safe_response_for_catalog_variant(): void
    {
        $fixture = $this->makeFixture();

        $response = $this->actingAs($fixture['user'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_variant_id=' . $fixture['variant']->id));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'product_name' => $fixture['product']->display_name,
                'variant_name' => $fixture['variant']->display_name,
                'display_code' => 'ET-0506-MV',
                'current_stock' => 13600.0,
                'current_price_value' => 6.5,
                'stock_label' => '13.600',
                'quote_currency' => 'TRY',
            ]);
    }

    public function test_other_tenant_product_access_is_rejected(): void
    {
        $fixture = $this->makeFixture();
        $foreign = $this->makeFixture('foreign');

        $response = $this->actingAs($fixture['user'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_id=' . $foreign['product']->id));

        $response->assertNotFound()
            ->assertJson([
                'ok' => false,
                'public_safe_message' => 'Bu ürün bilgisi güvenli şekilde okunamadı.',
            ]);
    }

    public function test_supplier_access_closed_product_is_not_sellable(): void
    {
        $fixture = $this->makeFixture('closed', [
            'access_open' => false,
        ]);

        $response = $this->actingAs($fixture['user'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_variant_id=' . $fixture['variant']->id));

        $response->assertOk()
            ->assertJson([
                'ok' => false,
                'supplier_access_active' => false,
                'public_safe_message' => 'Bu ürün şu anda teklif için uygun değil.',
            ])
            ->assertJsonFragment(['Abone Firma bu tedarikçiye erişemiyor.']);
    }

    public function test_quote_visible_false_product_is_not_sellable(): void
    {
        $fixture = $this->makeFixture('hidden', [
            'visible_in_quote' => false,
            'variant_quote_visible' => false,
        ]);

        $response = $this->actingAs($fixture['user'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_variant_id=' . $fixture['variant']->id));

        $response->assertOk()
            ->assertJson([
                'ok' => false,
                'quote_visible' => false,
            ])
            ->assertJsonFragment(['Ürün teklifte kullanıma kapalı.']);
    }

    public function test_category_pending_product_stays_sellable_when_search_visibility_is_open(): void
    {
        $fixture = $this->makeFixture('category-pending', [
            'catalog_status' => 'category_pending',
        ]);

        $response = $this->actingAs($fixture['user'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_variant_id=' . $fixture['variant']->id));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'tenant_catalog_active' => true,
                'quote_visible' => true,
                'public_safe_message' => 'Ürün güncel ve teklif için uygun.',
            ])
            ->assertJsonFragment(['Kategori eşleşmemiş.'])
            ->assertJsonFragment(['Genel kategori henüz bağlanmadı.'])
            ->assertJsonMissing([
                'product_inactive_warning' => 'Bu ürün şu anda aktif değil.',
            ])
            ->assertJsonMissing([
                'warnings' => ['Bu ürün şu anda aktif değil.'],
            ]);
    }

    public function test_snapshot_price_difference_is_reported(): void
    {
        $fixture = $this->makeFixture();

        $response = $this->actingAs($fixture['user'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_variant_id=' . $fixture['variant']->id . '&snapshot_price=5.90'));

        $response->assertOk()
            ->assertJson([
                'price_changed_since_snapshot' => true,
            ])
            ->assertJsonFragment(['Bu ürünün güncel fiyatı teklif satırındaki fiyattan farklı.']);
    }

    public function test_snapshot_stock_difference_is_reported(): void
    {
        $fixture = $this->makeFixture();

        $response = $this->actingAs($fixture['user'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_variant_id=' . $fixture['variant']->id . '&snapshot_stock=12000'));

        $response->assertOk()
            ->assertJson([
                'stock_changed_since_snapshot' => true,
            ])
            ->assertJsonFragment(['Stok bilgisi değişmiş olabilir.']);
    }

    public function test_document_currency_snapshot_match_does_not_flag_price_difference_for_foreign_source_price(): void
    {
        $fixture = $this->makeFixture('usd-source', [
            'product_currency' => 'USD',
            'variant_currency' => 'USD',
            'product_display_price' => 12.5,
            'variant_display_price' => 12.5,
            'product_price_snapshot' => [
                'list_price' => 12.5,
                'source_price' => 12.5,
                'source_currency' => 'USD',
                'currency' => 'USD',
                'base_price' => 437.5,
                'base_currency' => 'TRY',
                'conversion_status' => 'converted',
                'purchase_price' => 4.1,
                'supplier_warning_flag' => false,
                'currency_snapshot' => [
                    'source_price' => 12.5,
                    'source_currency' => 'USD',
                    'base_price' => 437.5,
                    'base_currency' => 'TRY',
                    'conversion_status' => 'converted',
                    'rate_date' => '2026-07-10',
                ],
            ],
            'variant_price_snapshot' => [
                'list_price' => 12.5,
                'source_price' => 12.5,
                'source_currency' => 'USD',
                'currency' => 'USD',
                'base_price' => 437.5,
                'base_currency' => 'TRY',
                'conversion_status' => 'converted',
                'purchase_price' => 4.1,
                'currency_snapshot' => [
                    'source_price' => 12.5,
                    'source_currency' => 'USD',
                    'base_price' => 437.5,
                    'base_currency' => 'TRY',
                    'conversion_status' => 'converted',
                    'rate_date' => '2026-07-10',
                ],
            ],
        ]);

        $baseUrl = $this->tenantUrl($fixture['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_variant_id=' . $fixture['variant']->id . '&currency=TRY');
        $initial = $this->actingAs($fixture['user'], 'web')->getJson($baseUrl);

        $initial->assertOk()
            ->assertJson([
                'current_price_value' => 12.5,
                'currency' => 'USD',
                'quote_currency' => 'TRY',
            ]);

        $quotePriceValue = data_get($initial->json(), 'quote_price_value');

        $this->assertNotNull($quotePriceValue);
        $this->assertNotSame(12.5, (float) $quotePriceValue);

        $followUp = $this->actingAs($fixture['user'], 'web')
            ->getJson($baseUrl . '&snapshot_price=' . $quotePriceValue);

        $followUp->assertOk()
            ->assertJson([
                'price_changed_since_snapshot' => false,
                'currency' => 'USD',
                'quote_currency' => 'TRY',
            ]);
    }

    public function test_quote_item_snapshot_comparison_uses_same_tenant_item_only(): void
    {
        $fixture = $this->makeFixture();
        $quote = Order::query()->create([
            'tenant_account_id' => $fixture['tenant']->id,
            'order_family' => 'promotion',
            'order_mode' => 'manual',
            'document_type' => 'quote',
            'document_number' => 'TK-LIVE-' . strtoupper(substr(uniqid(), -6)),
            'status' => 'draft',
            'workflow_status' => 'draft',
            'customer_approval_status' => 'not_sent',
            'quote_date' => now()->toDateString(),
            'valid_until' => now()->addWeek()->toDateString(),
            'currency' => 'TL',
            'subtotal' => 57.5,
            'vat_total' => 0,
            'grand_total' => 57.5,
            'product_total' => 57.5,
            'print_total' => 0,
            'created_by' => $fixture['user']->id,
        ]);

        $quoteItem = OrderItem::query()->create([
            'tenant_account_id' => $fixture['tenant']->id,
            'order_id' => $quote->id,
            'tenant_catalog_product_id' => $fixture['product']->id,
            'tenant_catalog_product_variant_id' => $fixture['variant']->id,
            'item_type' => 'product',
            'product_source' => 'supplier_feed',
            'product_name' => $fixture['variant']->display_name,
            'product_code' => $fixture['variant']->variant_code,
            'product_snapshot' => [],
            'price_snapshot' => ['list_price' => 5.75],
            'stock_snapshot' => ['visible_stock_quantity' => 14000],
            'catalog_source' => 'supplier_projection',
            'list_price' => 5.75,
            'unit_price' => 5.75,
            'line_total' => 57.5,
            'quantity' => 10,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($fixture['user'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_variant_id=' . $fixture['variant']->id . '&quote_item_id=' . $quoteItem->id));

        $response->assertOk()
            ->assertJson([
                'price_changed_since_snapshot' => true,
                'stock_changed_since_snapshot' => true,
            ]);
    }

    public function test_sensitive_fields_do_not_leak_in_response(): void
    {
        $fixture = $this->makeFixture();

        $response = $this->actingAs($fixture['user'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_variant_id=' . $fixture['variant']->id));

        $response->assertOk();
        $json = json_encode($response->json(), JSON_UNESCAPED_UNICODE);

        foreach ([
            'purchase_price',
            'supplier_price',
            'cost',
            'raw_price',
            'raw_payload',
            'group_code',
            'supplier_source_id',
            'standard_product_id',
            'standard_product_variant_id',
            'tenant_id',
            'file_path',
            'physical_path',
            'api_key',
            'token',
            'secret',
            'smtp_password',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_missing_or_invalid_params_return_safe_validation_error(): void
    {
        $fixture = $this->makeFixture();

        $missing = $this->actingAs($fixture['user'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/product-hub/live-product-info'));

        $missing->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'public_safe_message' => 'Ürün seçimi eksik.',
            ]);

        $invalid = $this->actingAs($fixture['user'], 'web')
            ->getJson($this->tenantUrl($fixture['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_id=abc'));

        $invalid->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'public_safe_message' => 'Gönderilen ürün bilgisi doğrulanamadı.',
            ]);

        $this->assertStringNotContainsString('Urun guncel', json_encode($invalid->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_guest_access_is_blocked(): void
    {
        $fixture = $this->makeFixture();

        $response = $this->get($this->tenantUrl($fixture['tenant'], '/admin/product-hub/live-product-info?tenant_catalog_product_id=' . $fixture['product']->id));

        $response->assertRedirect('/login');
    }

    private function makeFixture(string $suffix = 'main', array $overrides = []): array
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Live Info Tenant ' . $suffix,
            'legal_name' => 'Live Info Tenant ' . $suffix,
            'slug' => 'live-info-' . $suffix . '-' . uniqid(),
            'panel_subdomain' => 'live-info-' . $suffix . '-' . uniqid(),
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $user = User::query()->create([
            'name' => 'Live Info User ' . $suffix,
            'email' => 'live-info-' . $suffix . '-' . uniqid() . '@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Live Info Supplier ' . $suffix,
            'code' => 'LIS-' . strtoupper(substr(uniqid(), -6)),
            'status' => 'active',
        ]);

        $standardProduct = StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'ET-0506-' . strtoupper(substr($suffix, 0, 3)),
            'sku' => 'ET-0506-' . strtoupper(substr($suffix, 0, 3)),
            'product_name' => 'Plastik Tukenmez Kalem',
            'base_product_name' => 'Plastik Tukenmez Kalem',
            'name' => 'Plastik Tukenmez Kalem',
            'slug' => 'plastik-tukenmez-kalem-' . uniqid(),
            'standard_category_id' => $this->category->id,
            'product_family' => 'promotion',
            'image_url' => 'https://example.test/pen.jpg',
            'currency' => 'TL',
            'min_purchase_price' => 6.5,
            'max_purchase_price' => 6.5,
            'total_stock_quantity' => 13600,
            'supplier_count' => 1,
            'variant_count' => 1,
            'visible_in_catalog' => true,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'supplier_product_code' => 'ET-0506',
                'supplier_group_code' => '0506',
            ]],
            'meta' => [
                'price_snapshot' => [
                    'list_price' => 6.5,
                    'purchase_price' => 4.1,
                    'vat_rate' => 20,
                ],
            ],
            'is_active' => true,
        ]);

        $standardVariant = StandardProductVariant::query()->create([
            'standard_product_id' => $standardProduct->id,
            'tenant_account_id' => $tenant->id,
            'variant_code' => '0506-MV',
            'generated_variant_code' => 'ET-0506-MV',
            'variant_name' => 'Mavi',
            'variant_color' => 'Mavi',
            'variant_size' => null,
            'variant_attributes' => [],
            'stock_quantity' => 13600,
            'min_purchase_price' => 6.5,
            'max_purchase_price' => 6.5,
            'supplier_count' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [
                'supplier_id' => $supplier->id,
                'supplier_source_id' => 999,
                'variant_stock_code' => '0506-MV',
                'supplier_group_code' => '0506',
            ],
            'meta' => [
                'price_snapshot' => ['list_price' => 6.5, 'purchase_price' => 4.1],
            ],
        ]);

        $product = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $standardProduct->id,
            'tenant_sku' => 'TEN-ET-0506-' . strtoupper(substr(uniqid(), -6)),
            'name' => 'Plastik Tukenmez Kalem',
            'product_code' => 'ET-0506',
            'product_name' => 'Plastik Tukenmez Kalem',
            'slug' => 'tenant-pen-' . uniqid(),
            'standard_category_id' => $this->category->id,
            'product_family' => 'promotion',
            'display_price' => $overrides['product_display_price'] ?? 6.5,
            'sale_price' => $overrides['product_display_price'] ?? 6.5,
            'currency' => $overrides['product_currency'] ?? 'TL',
            'total_stock_quantity' => 13600,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 13600,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'supplier_source_id' => 999,
                'supplier_product_code' => 'ET-0506',
                'supplier_group_code' => '0506',
            ]],
            'visible_in_catalog' => true,
            'visible_in_quote' => $overrides['visible_in_quote'] ?? true,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => $overrides['catalog_status'] ?? 'ready',
            'last_synced_at' => now(),
            'meta' => [
                'price_snapshot' => [
                    ...($overrides['product_price_snapshot'] ?? []),
                    'list_price' => data_get($overrides, 'product_price_snapshot.list_price', $overrides['product_display_price'] ?? 6.5),
                    'purchase_price' => data_get($overrides, 'product_price_snapshot.purchase_price', 4.1),
                    'supplier_warning_flag' => data_get($overrides, 'product_price_snapshot.supplier_warning_flag', false),
                ],
                'is_parent' => false,
                'is_sellable' => true,
            ],
            'is_active' => $overrides['product_active'] ?? true,
            'stock_quantity' => 13600,
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
        ]);

        $variant = TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'standard_product_variant_id' => $standardVariant->id,
            'variant_code' => 'ET-0506-MV',
            'variant_name' => 'Mavi',
            'variant_color' => 'Mavi',
            'display_price' => $overrides['variant_display_price'] ?? 6.5,
            'currency' => $overrides['variant_currency'] ?? 'TL',
            'stock_quantity' => 13600,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 13600,
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'is_active' => $overrides['variant_active'] ?? true,
            'source_summary' => [
                'supplier_id' => $supplier->id,
                'supplier_source_id' => 999,
                'supplier_product_code' => 'ET-0506-MV',
                'supplier_group_code' => '0506',
                'variant_stock_code' => '0506-MV',
            ],
            'meta' => [
                'quote_search_visible' => $overrides['variant_quote_visible'] ?? true,
                'price_snapshot' => [
                    ...($overrides['variant_price_snapshot'] ?? []),
                    'list_price' => data_get($overrides, 'variant_price_snapshot.list_price', $overrides['variant_display_price'] ?? 6.5),
                    'purchase_price' => data_get($overrides, 'variant_price_snapshot.purchase_price', 4.1),
                ],
                'raw_payload' => ['secret' => 'must-not-leak'],
            ],
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => $overrides['access_open'] ?? true,
            'can_view_products' => $overrides['access_open'] ?? true,
            'visible_in_catalog' => $overrides['access_open'] ?? true,
            'can_use_in_quotes' => $overrides['access_open'] ?? true,
            'can_request_purchase' => true,
            'price_multiplier' => 1,
            'safe_stock_quantity' => 0,
            'export_allowed' => false,
        ]);

        return compact('tenant', 'user', 'supplier', 'product', 'variant');
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
