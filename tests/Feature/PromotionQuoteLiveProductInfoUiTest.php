<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\Supplier;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteLiveProductInfoUiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_create_form_renders_live_product_info_container_and_hook(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertDontSee('Canlı Ürün Bilgisi');
        $response->assertSee('Güncel fiyat:');
        $response->assertSee('Güncellendi:');
        $response->assertSee('Kırmızı Ürün');
        $response->assertSee('Kur bilgisi bulunamadı');
        $response->assertSee('Teklifte kullanılamaz');
        $response->assertDontSee('Kategori uyarısı');
        $response->assertDontSee('Güncel fiyat farklı');
        $response->assertSee('pd-product-live-info__meta-line', false);
        $response->assertDontSee('pd-product-live-info__meta-row', false);
        $response->assertDontSee('pd-product-live-info__meta-bit', false);
        $response->assertSee('Ürün seçildiğinde güncel stok ve fiyat bilgisi burada görünür.');
        $response->assertSee('Canlı ürün bilgisi güncelleniyor...');
        $response->assertSee('Canlı ürün bilgisi şu anda alınamadı.');
        $response->assertSee('data-live-product-info-box', false);
        $response->assertSee('data-live-product-info-endpoint=', false);
        $response->assertSee('data-tenant-catalog-product-id=', false);
        $response->assertSee('data-tenant-catalog-product-variant-id=', false);
        $response->assertSee('data-quote-item-id=', false);
        $response->assertSee('quoteWorkspace.liveProductInfoUrl', false);
        $response->assertSee('function renderLiveProductInfoPanel(item = {}) {', false);
        $response->assertSee('async function ensureLiveProductInfo(item = {}) {', false);
        $response->assertSee('function buildLiveProductInfoWarnings(payload = {}) {', false);
        $response->assertSee('const liveProductInfoState = new Map();', false);
        $response->assertDontSee('pd-product-live-info__compact-grid', false);
        $response->assertDontSee('pd-product-live-info__metric', false);
        $response->assertDontSee('pd-product-live-info__header', false);
        $response->assertDontSee('pd-product-live-info__status', false);
        $response->assertDontSee('Uyarılar');
        $response->assertDontSee('Güncel stok');
        $response->assertDontSee('Satış durumu');
        $response->assertDontSee('Ürün güncel ve teklif için uygun.');
        $this->assertLessThanOrEqual(1, substr_count($response->getContent(), 'Teklife uygun'));
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_payload', false);
        $response->assertDontSee('purchase_price', false);
        $response->assertDontSee('supplier_price', false);
        $response->assertDontSee('api_key', false);
        $response->assertDontSee('data-token=', false);
        $response->assertDontSee('secret', false);
        $response->assertDontSee('Guncel fiyat');
        $response->assertDontSee('Guncel stok');
        $response->assertDontSee('Satis durumu');
        $response->assertDontSee('Urun guncel ve teklif icin uygun.');
    }

    public function test_edit_form_sets_safe_live_info_data_attributes_without_sensitive_leak(): void
    {
        $fixture = $this->createQuoteWithCatalogItem();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.edit', $fixture['quote']));

        $response->assertOk();
        $response->assertDontSee('Canlı Ürün Bilgisi');
        $response->assertSee('Güncel fiyat:');
        $response->assertSee('Güncellendi:');
        $response->assertSee('Kırmızı Ürün');
        $response->assertDontSee('Kategori uyarısı');
        $response->assertDontSee('Güncel fiyat farklı');
        $response->assertSee('data-live-product-info-box', false);
        $response->assertSee('pd-product-live-info__meta-line', false);
        $response->assertDontSee('pd-product-live-info__meta-row', false);
        $response->assertSee('"quote_item_id":' . $fixture['item']->id, false);
        $response->assertSee('"tenant_catalog_product_id":' . $fixture['catalog_product']->id, false);
        $response->assertSee('"tenant_catalog_product_variant_id":' . $fixture['catalog_variant']->id, false);
        $response->assertSee('"list_price":"6.50"', false);
        $response->assertSee('"visible_stock_quantity":13600', false);
        $response->assertSee('data-live-product-info-endpoint=', false);
        $response->assertSee('data-quote-item-id="${escapeHtml(quoteItemId)}"', false);
        $response->assertSee('data-tenant-catalog-product-id="${escapeHtml(productId)}"', false);
        $response->assertSee('data-tenant-catalog-product-variant-id="${escapeHtml(variantId)}"', false);
        $response->assertDontSee('pd-product-live-info__compact-grid', false);
        $response->assertDontSee('pd-product-live-info__metric', false);
        $response->assertDontSee('pd-product-live-info__header', false);
        $response->assertDontSee('pd-product-live-info__status', false);
        $response->assertDontSee('raw_payload', false);
        $response->assertDontSee('purchase_price', false);
        $response->assertDontSee('supplier_price', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('api_key', false);
        $response->assertDontSee('secret', false);
        $response->assertDontSee('Guncel fiyat');
        $response->assertDontSee('Guncel stok');
        $response->assertDontSee('Satis durumu');
        $this->assertLessThanOrEqual(1, substr_count($response->getContent(), 'Teklife uygun'));
    }

    private function createQuoteWithCatalogItem(): array
    {
        $tenant = $this->adminUser->preferredTenant() ?: optional($this->adminUser->activeTenantRoles()->first())->tenant;
        $role = Role::query()->where('key', 'tenant_owner')->firstOrFail();

        if (!$this->adminUser->belongsToTenant($tenant)) {
            UserRole::query()->create([
                'tenant_account_id' => $tenant->id,
                'user_id' => $this->adminUser->id,
                'role_id' => $role->id,
            ]);
        }

        $customer = Company::query()->where('tenant_account_id', $tenant->id)->firstOrFail();
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $supplier = Supplier::query()->create([
            'name' => 'UI Live Supplier',
            'code' => 'UI-LIVE-' . strtoupper(substr(uniqid(), -6)),
            'status' => 'active',
        ]);
        $standardProduct = StandardProduct::query()->create([
            'supplier_id' => $supplier->id,
            'standard_product_code' => 'UI-LIVE-0506',
            'sku' => 'UI-LIVE-0506',
            'product_name' => 'UI Canlı Kalem',
            'base_product_name' => 'UI Canlı Kalem',
            'name' => 'UI Canlı Kalem',
            'slug' => 'ui-canli-kalem-' . uniqid(),
            'standard_category_id' => $category->id,
            'product_family' => 'promotion',
            'image_url' => 'https://example.test/ui-live.jpg',
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
                'supplier_product_code' => 'UI-LIVE-0506',
            ]],
            'meta' => [
                'price_snapshot' => [
                    'list_price' => 6.5,
                    'vat_rate' => 20,
                ],
            ],
            'is_active' => true,
        ]);
        $standardVariant = StandardProductVariant::query()->create([
            'standard_product_id' => $standardProduct->id,
            'tenant_account_id' => $tenant->id,
            'variant_code' => '0506-MV',
            'generated_variant_code' => 'UI-LIVE-0506-MV',
            'variant_name' => 'Mavi',
            'variant_color' => 'Mavi',
            'variant_attributes' => [],
            'stock_quantity' => 13600,
            'min_purchase_price' => 6.5,
            'max_purchase_price' => 6.5,
            'supplier_count' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [
                'supplier_id' => $supplier->id,
                'variant_stock_code' => '0506-MV',
            ],
            'meta' => [
                'price_snapshot' => ['list_price' => 6.5, 'vat_rate' => 20],
            ],
        ]);
        $catalogProduct = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $standardProduct->id,
            'tenant_sku' => 'TEN-UI-LIVE-' . strtoupper(substr(uniqid(), -6)),
            'name' => 'UI Canlı Kalem',
            'product_code' => 'UI-LIVE-0506',
            'product_name' => 'UI Canlı Kalem',
            'slug' => 'ui-live-tenant-' . uniqid(),
            'standard_category_id' => $category->id,
            'product_family' => 'promotion',
            'display_price' => 6.5,
            'sale_price' => 6.5,
            'currency' => 'TL',
            'total_stock_quantity' => 13600,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 13600,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'supplier_product_code' => 'UI-LIVE-0506',
            ]],
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'last_synced_at' => now(),
            'meta' => [
                'price_snapshot' => ['list_price' => 6.5, 'vat_rate' => 20],
                'is_parent' => false,
                'is_sellable' => true,
            ],
            'is_active' => true,
            'stock_quantity' => 13600,
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
        ]);
        $catalogVariant = TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $tenant->id,
            'tenant_catalog_product_id' => $catalogProduct->id,
            'standard_product_variant_id' => $standardVariant->id,
            'variant_code' => 'UI-LIVE-0506-MV',
            'variant_name' => 'Mavi',
            'variant_color' => 'Mavi',
            'display_price' => 6.5,
            'currency' => 'TL',
            'stock_quantity' => 13600,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 13600,
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => [
                'supplier_id' => $supplier->id,
                'variant_stock_code' => '0506-MV',
            ],
            'meta' => [
                'quote_search_visible' => true,
                'price_snapshot' => ['list_price' => 6.5, 'vat_rate' => 20],
            ],
        ]);

        $quote = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-UI-LIVE-' . strtoupper(substr(uniqid(), -5)),
            'customer_company_id' => $customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => now()->toDateString(),
            'invoice_status' => 'fis',
            'currency' => 'TL',
            'subtotal' => 650,
            'vat_total' => 0,
            'grand_total' => 650,
            'product_total' => 650,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $quote->id,
            'tenant_catalog_product_id' => $catalogProduct->id,
            'tenant_catalog_product_variant_id' => $catalogVariant->id,
            'standard_product_id' => $standardProduct->id,
            'standard_product_variant_id' => $standardVariant->id,
            'item_type' => 'product',
            'product_source' => 'supplier_feed',
            'product_name' => 'UI-LIVE-0506-MV Mavi',
            'product_code' => 'UI-LIVE-0506-MV',
            'quantity' => 100,
            'unit' => 'Adet',
            'list_price' => 6.5,
            'discount_rate' => 0,
            'unit_price' => 6.5,
            'line_total' => 650,
            'has_print' => true,
            'print_total' => 0,
            'manual_unit_price' => true,
            'warning_badges' => ['Kırmızı Ürün'],
            'status' => 'draft',
            'catalog_source' => 'supplier_projection',
            'product_snapshot' => [
                'product_name' => 'UI Canlı Kalem',
                'product_code' => 'UI-LIVE-0506-MV',
                'tenant_catalog_product_id' => $catalogProduct->id,
                'tenant_catalog_product_variant_id' => $catalogVariant->id,
                'visible_in_catalog' => true,
                'visible_in_quote' => true,
            ],
            'price_snapshot' => [
                'list_price' => 6.5,
                'vat_rate' => 20,
                'manual_unit_price' => true,
            ],
            'stock_snapshot' => [
                'visible_stock_quantity' => 13600,
                'supplier_stock_quantity' => 13600,
            ],
        ]);

        $item->prints()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $quote->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf baskı',
            'production_type' => 'İç üretim',
            'print_quantity' => 100,
            'print_unit_price' => 0,
            'print_total' => 0,
            'note' => 'UI test baskı',
            'status' => 'draft',
        ]);

        return [
            'quote' => $quote,
            'item' => $item,
            'catalog_product' => $catalogProduct,
            'catalog_variant' => $catalogVariant,
        ];
    }
}
