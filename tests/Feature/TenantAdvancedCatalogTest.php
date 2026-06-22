<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockMovement;
use App\Models\StandardCategory;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierPurchaseEntry;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TenantAdvancedCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
    }

    public function test_tenant_only_sees_open_supplier_products(): void
    {
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $openSupplier = Supplier::query()->create(['name' => 'Acik Tedarikci', 'code' => 'OPEN-SUP', 'status' => 'active']);
        $closedSupplier = Supplier::query()->create(['name' => 'Kapali Tedarikci', 'code' => 'CLOSED-SUP', 'status' => 'active']);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $openSupplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'visible_in_catalog' => true,
            'can_use_in_quotes' => true,
            'can_request_purchase' => true,
            'price_multiplier' => 1,
            'safe_stock_quantity' => 0,
            'export_allowed' => false,
        ]);

        $visibleProduct = $this->makeCatalogProduct([
            'tenant_account_id' => $this->tenant->id,
            'product_code' => 'OPEN-001',
            'product_name' => 'Acik Urun',
            'standard_category_id' => $category->id,
            'source_summary' => [['supplier_id' => $openSupplier->id, 'supplier_name' => $openSupplier->name]],
        ]);

        $blockedProduct = $this->makeCatalogProduct([
            'tenant_account_id' => $this->tenant->id,
            'product_code' => 'CLOSED-001',
            'product_name' => 'Kapali Urun',
            'standard_category_id' => $category->id,
            'source_summary' => [['supplier_id' => $closedSupplier->id, 'supplier_name' => $closedSupplier->name]],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog/supplier-products');

        $response->assertOk();
        $response->assertSeeText($visibleProduct->display_name);
        $response->assertDontSeeText($blockedProduct->display_name);
    }

    public function test_supplier_dropdown_and_catalog_keep_sellable_variants_when_parent_groups_are_hidden(): void
    {
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $suppliers = collect([
            ['name' => 'Yeni Nesil', 'code' => 'YENI-REG'],
            ['name' => 'Etkin', 'code' => 'ETKIN-REG'],
            ['name' => 'Akdeniz', 'code' => 'AKDENIZ-REG'],
            ['name' => 'İlpen', 'code' => 'ILPEN-REG'],
        ])->map(function (array $supplier) {
            $model = Supplier::query()->create([
                'name' => $supplier['name'],
                'code' => $supplier['code'],
                'status' => 'active',
            ]);

            TenantSupplierAccess::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'supplier_id' => $model->id,
                'is_active' => true,
                'can_view_products' => true,
                'visible_in_catalog' => true,
                'can_use_in_quotes' => true,
                'can_request_purchase' => true,
                'price_multiplier' => 1,
                'safe_stock_quantity' => 0,
                'export_allowed' => false,
            ]);

            return $model;
        })->keyBy(fn (Supplier $supplier) => str($supplier->code)->before('-REG')->toString());

        $this->makeCatalogProduct([
            'product_code' => 'YN-001',
            'product_name' => 'YN-001 Satılabilir Flat',
            'standard_category_id' => $category->id,
            'source_summary' => [['supplier_id' => $suppliers['YENI']->id, 'supplier_name' => $suppliers['YENI']->name]],
        ]);
        $this->makeCatalogProduct([
            'product_code' => 'IL-001',
            'product_name' => 'IL-001 Satılabilir Flat',
            'standard_category_id' => $category->id,
            'source_summary' => [['supplier_id' => $suppliers['ILPEN']->id, 'supplier_name' => $suppliers['ILPEN']->name]],
        ]);

        foreach ([
            ['supplier' => $suppliers['ETKIN'], 'parent_code' => 'ET-0506', 'variant_code' => 'ET-0506-L', 'variant_name' => 'Plastik Kalem Lacivert'],
            ['supplier' => $suppliers['AKDENIZ'], 'parent_code' => 'AK-PB-4007', 'variant_code' => 'AK-PB-4007-SIYAH', 'variant_name' => 'Powerbank Siyah'],
        ] as $fixture) {
            $parent = $this->makeCatalogProduct([
                'product_code' => $fixture['parent_code'],
                'product_name' => $fixture['parent_code'] . ' Grup Ürün',
                'standard_category_id' => $category->id,
                'source_summary' => [[
                    'supplier_id' => $fixture['supplier']->id,
                    'supplier_name' => $fixture['supplier']->name,
                    'supplier_product_code' => $fixture['parent_code'],
                    'supplier_group_code' => $fixture['parent_code'],
                ]],
                'visible_in_quote' => false,
                'meta' => [
                    'is_parent' => true,
                    'is_sellable' => false,
                    'supplier_group_code' => $fixture['parent_code'],
                    'price_snapshot' => ['list_price' => 100, 'vat_rate' => 20],
                    'warning_snapshot' => [],
                ],
            ]);

            TenantCatalogProductVariant::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'tenant_catalog_product_id' => $parent->id,
                'variant_code' => $fixture['variant_code'],
                'variant_name' => $fixture['variant_name'],
                'variant_color' => 'Siyah',
                'image_url' => 'https://example.test/' . strtolower($fixture['variant_code']) . '.jpg',
                'display_price' => 100,
                'currency' => 'TL',
                'stock_quantity' => 25,
                'local_stock_quantity' => 0,
                'supplier_stock_quantity' => 25,
                'safe_stock_quantity' => 0,
                'visible_in_catalog' => true,
                'is_active' => true,
                'source_summary' => [
                    'supplier_id' => $fixture['supplier']->id,
                    'supplier_name' => $fixture['supplier']->name,
                    'supplier_product_code' => $fixture['variant_code'],
                    'supplier_group_code' => $fixture['parent_code'],
                ],
                'meta' => [
                    'is_variant' => true,
                    'is_sellable' => true,
                    'quote_search_visible' => true,
                    'parent_product_code' => $fixture['parent_code'],
                    'supplier_group_code' => $fixture['parent_code'],
                    'price_snapshot' => ['list_price' => 100, 'vat_rate' => 20],
                ],
            ]);
        }

        $catalogResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog/supplier-products');

        $catalogResponse->assertOk();
        $catalogResponse->assertSeeText('Yeni Nesil');
        $catalogResponse->assertSeeText('Etkin');
        $catalogResponse->assertSeeText('Akdeniz');
        $catalogResponse->assertSeeText('İlpen');
        $catalogResponse->assertSeeText('ET-0506-L');
        $catalogResponse->assertSeeText('AK-PB-4007-SIYAH');
        $catalogResponse->assertDontSeeText('ET-0506 Grup Ürün');
        $catalogResponse->assertDontSeeText('AK-PB-4007 Grup Ürün');
        $catalogResponse->assertSeeText('Local Stoğa Al');

        $searchResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog/search?q=ET-0506');

        $searchResponse->assertOk();
        $searchResponse->assertJsonFragment(['product_code' => 'ET-0506-L']);
        $searchResponse->assertJsonMissing(['product_code' => 'ET-0506']);
        $this->assertArrayNotHasKey('group_product_code', $searchResponse->json()[0] ?? []);
    }

    public function test_local_stock_priority_and_supplier_stock_fallback_are_used_in_catalog_search(): void
    {
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();

        $priorityProduct = $this->makeCatalogProduct([
            'tenant_account_id' => $this->tenant->id,
            'product_code' => 'LOCAL-FIRST',
            'product_name' => 'Local Oncelikli Urun',
            'standard_category_id' => $category->id,
            'local_stock_quantity' => 12,
            'supplier_stock_quantity' => 2531,
            'total_stock_quantity' => 2543,
        ]);

        $supplierProduct = $this->makeCatalogProduct([
            'tenant_account_id' => $this->tenant->id,
            'product_code' => 'SUP-FIRST',
            'product_name' => 'Tedarikci Stoklu Urun',
            'standard_category_id' => $category->id,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 2531,
            'total_stock_quantity' => 2531,
        ]);

        $priorityResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->getJson('/admin/catalog/search?q=LOCAL-FIRST');

        $priorityResponse->assertOk();
        $priorityPayload = $priorityResponse->json();
        $this->assertSame(12.0, (float) $priorityPayload[0]['visible_stock_quantity']);
        $this->assertTrue((bool) $priorityPayload[0]['local_stock_priority']);

        $supplierResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->getJson('/admin/catalog/search?q=SUP-FIRST');

        $supplierResponse->assertOk();
        $supplierPayload = $supplierResponse->json();
        $this->assertSame(2531.0, (float) $supplierPayload[0]['visible_stock_quantity']);
        $this->assertFalse((bool) $supplierPayload[0]['local_stock_priority']);
    }

    public function test_hidden_and_quote_closed_products_do_not_appear_in_search(): void
    {
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();

        $hiddenProduct = $this->makeCatalogProduct([
            'tenant_account_id' => $this->tenant->id,
            'product_code' => 'HIDDEN-001',
            'product_name' => 'Gizli Urun',
            'standard_category_id' => $category->id,
            'visible_in_catalog' => false,
        ]);

        $quoteClosedProduct = $this->makeCatalogProduct([
            'tenant_account_id' => $this->tenant->id,
            'product_code' => 'QUOTE-OFF',
            'product_name' => 'Teklifte Kapali',
            'standard_category_id' => $category->id,
            'visible_in_quote' => false,
        ]);

        $search = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->getJson('/admin/catalog/search?q=001');

        $search->assertOk();
        $search->assertJsonMissing(['product_code' => $hiddenProduct->product_code]);
        $search->assertJsonMissing(['product_code' => $quoteClosedProduct->product_code]);
    }

    public function test_tenant_can_create_local_product_and_cannot_see_other_tenants_local_products(): void
    {
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $otherTenant = TenantAccount::query()->create([
            'name' => 'Diger Tenant',
            'legal_name' => 'Diger Tenant A.S.',
            'slug' => 'diger-tenant',
            'panel_subdomain' => 'diger-tenant',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/catalog/local-products', [
                'product_name' => 'Tenant Local Mug',
                'product_code' => 'LOCAL-MUG-01',
                'standard_category_id' => $category->id,
                'display_price' => 145.50,
                'currency' => 'TL',
                'vat_rate' => 20,
                'local_stock_quantity' => 8,
                'visible_in_catalog' => '1',
                'visible_in_quote' => '1',
                'is_active' => '1',
            ]);

        $response->assertRedirect('/admin/catalog/local-products');
        $this->assertDatabaseHas('tenant_catalog_products', [
            'tenant_account_id' => $this->tenant->id,
            'product_code' => 'LOCAL-MUG-01',
            'catalog_source' => 'local_product',
        ]);

        $this->makeCatalogProduct([
            'tenant_account_id' => $otherTenant->id,
            'product_code' => 'OTHER-LOCAL',
            'product_name' => 'Baska Tenant Local',
            'catalog_source' => 'local_product',
            'standard_product_id' => null,
        ]);

        $page = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog/local-products');

        $page->assertOk();
        $page->assertSeeText('LOCAL-MUG-01');
        $page->assertDontSeeText('OTHER-LOCAL');
    }

    public function test_warning_screen_and_search_show_price_and_policy_warnings(): void
    {
        $warningProduct = $this->makeCatalogProduct([
            'tenant_account_id' => $this->tenant->id,
            'product_code' => 'WARN-001',
            'product_name' => 'Uyarili Urun',
            'display_price' => null,
            'image_url' => null,
            'standard_category_id' => null,
            'meta' => [
                'net_price_warning' => true,
                'supplier_warning_flag' => true,
                'warning_snapshot' => ['Net fiyat uyarısı'],
                'price_snapshot' => [
                    'list_price' => null,
                    'net_price_warning' => true,
                    'supplier_warning_flag' => true,
                ],
            ],
        ]);

        $page = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog/warnings');

        $page->assertOk();
        $page->assertSeeText('Fiyat eksik');
        $page->assertSeeText('Net fiyat uyarısı');

        $search = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->getJson('/admin/catalog/search?q=WARN-001');

        $payload = $search->json();
        $this->assertContains('Fiyat eksik', $payload[0]['warning_badges']);
        $this->assertContains('Net fiyat uyarısı', $payload[0]['warning_badges']);
    }

    public function test_tenant_can_edit_and_deactivate_local_product(): void
    {
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $product = $this->makeCatalogProduct([
            'tenant_account_id' => $this->tenant->id,
            'catalog_source' => 'local_product',
            'product_code' => 'LOCAL-EDIT-01',
            'product_name' => 'Eski Local Urun',
            'standard_category_id' => $category->id,
        ]);

        $updateResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put('/admin/catalog/local-products/' . $product->id, [
                'product_name' => 'Guncel Local Urun',
                'product_code' => 'LOCAL-EDIT-01',
                'standard_category_id' => $category->id,
                'display_price' => 199,
                'currency' => 'TL',
                'vat_rate' => 20,
                'local_stock_quantity' => 15,
                'visible_in_catalog' => '1',
                'visible_in_quote' => '1',
                'is_active' => '1',
                'local_stock_priority' => '1',
            ]);

        $updateResponse->assertRedirect('/admin/catalog/local-products');
        $this->assertDatabaseHas('tenant_catalog_products', [
            'id' => $product->id,
            'product_name' => 'Guncel Local Urun',
            'stock_quantity' => 15,
        ]);

        $deactivateResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/catalog/local-products/' . $product->id . '/deactivate');

        $deactivateResponse->assertRedirect();
        $this->assertDatabaseHas('tenant_catalog_products', [
            'id' => $product->id,
            'is_active' => false,
            'visible_in_catalog' => false,
            'visible_in_quote' => false,
        ]);
    }

    public function test_used_local_product_is_archived_instead_of_hard_deleted(): void
    {
        $product = $this->makeCatalogProduct([
            'tenant_account_id' => $this->tenant->id,
            'catalog_source' => 'local_product',
            'product_code' => 'LOCAL-USED-01',
            'product_name' => 'Kullanilmis Local Urun',
        ]);

        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'Q-LOCAL-001',
            'status' => 'draft',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'tenant_catalog_product_id' => $product->id,
            'item_type' => 'product',
            'product_source' => 'local_stock',
            'product_name' => $product->display_name,
            'product_code' => $product->display_code,
            'quantity' => 1,
            'unit' => 'Adet',
            'list_price' => 100,
            'unit_price' => 100,
            'line_total' => 100,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->delete('/admin/catalog/local-products/' . $product->id);

        $response->assertRedirect();
        $this->assertDatabaseHas('tenant_catalog_products', [
            'id' => $product->id,
            'catalog_status' => 'local_archived',
            'is_active' => false,
        ]);
    }

    public function test_unused_local_product_can_be_deleted(): void
    {
        $product = $this->makeCatalogProduct([
            'tenant_account_id' => $this->tenant->id,
            'catalog_source' => 'local_product',
            'product_code' => 'LOCAL-DELETE-01',
            'product_name' => 'Silinebilir Local Urun',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->delete('/admin/catalog/local-products/' . $product->id);

        $response->assertRedirect();
        $this->assertDatabaseMissing('tenant_catalog_products', [
            'id' => $product->id,
        ]);
    }

    public function test_bulk_visibility_update_works(): void
    {
        $first = $this->makeCatalogProduct([
            'tenant_account_id' => $this->tenant->id,
            'product_code' => 'VIS-001',
            'product_name' => 'Gorunurluk Bir',
        ]);
        $second = $this->makeCatalogProduct([
            'tenant_account_id' => $this->tenant->id,
            'product_code' => 'VIS-002',
            'product_name' => 'Gorunurluk Iki',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/catalog/visibility/bulk-update', [
                'action' => 'disable_quote',
                'selected_products' => [$first->id, $second->id],
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tenant_catalog_products', ['id' => $first->id, 'visible_in_quote' => false]);
        $this->assertDatabaseHas('tenant_catalog_products', ['id' => $second->id, 'visible_in_quote' => false]);
    }

    public function test_catalog_pages_show_filters_limits_and_pagination(): void
    {
        $this->makeCatalogProduct(['product_code' => 'PAGE-001', 'product_name' => 'Sayfalama Urunu']);

        foreach ([
            '/admin/catalog?limit=50',
            '/admin/catalog?limit=100&search=PAGE',
            '/admin/catalog/supplier-products?limit=250',
            '/admin/catalog/local-products?limit=500',
            '/admin/catalog/visibility?limit=50',
            '/admin/catalog/warnings?limit=50',
        ] as $url) {
            $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->get($url)
                ->assertOk()
                ->assertSeeText('Kayıt limiti')
                ->assertSeeText('Toplam');
        }
    }

    public function test_supplier_purchase_adds_local_stock_and_creates_payable_entry(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Borclu Tedarikci', 'code' => 'PAY-SUP', 'status' => 'active']);
        $product = $this->makeCatalogProduct([
            'product_code' => 'PAY-001',
            'product_name' => 'Borclu Urun',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
            'local_stock_quantity' => 2,
            'supplier_stock_quantity' => 10,
            'total_stock_quantity' => 12,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/catalog/' . $product->id . '/local-stock-entry', [
                'entry_type' => 'supplier_purchase',
                'quantity' => 5,
                'unit_purchase_price' => 10,
                'currency' => 'TL',
                'vat_enabled' => '1',
                'vat_rate' => 20,
                'document_no' => 'FAT-001',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tenant_catalog_products', [
            'id' => $product->id,
            'local_stock_quantity' => 7,
        ]);
        $this->assertDatabaseHas('tenant_supplier_purchase_entries', [
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'entry_type' => 'supplier_purchase',
            'payable_status' => 'open',
            'document_no' => 'FAT-001',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'movement_type' => 'in',
            'reason' => 'purchase',
        ]);
    }

    public function test_supplier_local_stock_product_is_visible_on_local_products_and_search(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Local Stok Tedarikci', 'code' => 'LOCAL-STOCK-SUP', 'status' => 'active']);
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
        $product = $this->makeCatalogProduct([
            'product_code' => 'LOCAL-STOCK-001',
            'product_name' => 'Local Stoğa Alınan Tedarikçi Ürünü',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 20,
            'total_stock_quantity' => 20,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/catalog/' . $product->id . '/local-stock-entry', [
                'entry_type' => 'existing_stock',
                'quantity' => 7,
                'currency' => 'TL',
            ])
            ->assertRedirect();

        $localPage = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog/local-products');

        $localPage->assertOk();
        $localPage->assertSeeText('Tedarikçiden Local Stoğa Alınan Ürünler');
        $localPage->assertSeeText('LOCAL-STOCK-001');
        $localPage->assertSeeText('Tedarikçiden Local Stok');

        $search = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog/search?q=LOCAL-STOCK-001');

        $search->assertOk();
        $result = collect($search->json())->firstWhere('product_code', 'LOCAL-STOCK-001');
        $this->assertNotNull($result);
        $this->assertSame(7.0, (float) $result['local_stock_quantity']);
        $this->assertTrue((bool) $result['local_stock_priority']);
    }

    public function test_existing_stock_adds_local_stock_without_payable(): void
    {
        $product = $this->makeCatalogProduct([
            'product_code' => 'EXIST-001',
            'product_name' => 'Eldeki Urun',
            'local_stock_quantity' => 1,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/catalog/' . $product->id . '/local-stock-entry', [
                'entry_type' => 'existing_stock',
                'quantity' => 4,
                'currency' => 'TL',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tenant_catalog_products', [
            'id' => $product->id,
            'local_stock_quantity' => 5,
        ]);
        $this->assertDatabaseHas('tenant_supplier_purchase_entries', [
            'tenant_catalog_product_id' => $product->id,
            'entry_type' => 'existing_stock',
            'payable_status' => 'none',
            'payable_amount' => 0,
        ]);
    }

    public function test_local_product_csv_import_preview_and_store_creates_tenant_product(): void
    {
        $csv = "urun_kodu,urun_adi,stok,liste_fiyati,para_birimi,kdv_var,katalogda_gorunsun,teklifte_kullanilsin\nIMP-001,Import Local Urun,12,44.5,TL,1,1,1\n";
        $file = UploadedFile::fake()->createWithContent('local-products.csv', $csv);

        $preview = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/catalog/local-products/import/preview', ['file' => $file]);

        $preview->assertRedirect('/admin/catalog/local-products/import');

        $store = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/catalog/local-products/import', ['duplicate_policy' => 'update']);

        $store->assertRedirect('/admin/catalog/local-products');
        $this->assertDatabaseHas('tenant_catalog_products', [
            'tenant_account_id' => $this->tenant->id,
            'product_code' => 'IMP-001',
            'catalog_source' => 'local_product',
            'visible_in_quote' => true,
        ]);
    }

    public function test_parent_group_product_is_hidden_from_tenant_catalog_and_rejected_for_local_stock(): void
    {
        $parent = $this->makeCatalogProduct([
            'product_code' => 'GRP-001',
            'product_name' => 'Teknik Grup Urun',
            'visible_in_quote' => false,
            'meta' => [
                'is_parent' => true,
                'is_sellable' => false,
                'supplier_group_code' => 'GRP-001',
                'price_snapshot' => ['list_price' => 100, 'vat_rate' => 20],
            ],
        ]);

        $page = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog');

        $page->assertOk();
        $page->assertDontSeeText('GRP-001');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/catalog/' . $parent->id . '/local-stock-entry', [
                'entry_type' => 'existing_stock',
                'quantity' => 1,
            ]);

        $response->assertStatus(422);
        $response->assertSeeText('Grup ürünler local stoğa alınamaz');
    }

    public function test_supplier_purchase_uses_discount_calculation_and_manual_purchase_price(): void
    {
        $product = $this->makeCatalogProduct([
            'product_code' => 'MANUAL-PURCHASE',
            'product_name' => 'Manuel Alis Urunu',
            'display_price' => 100,
            'meta' => [
                'price_snapshot' => ['list_price' => 100, 'discount_rate' => 45, 'vat_rate' => 20],
                'warning_snapshot' => [],
            ],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/catalog/' . $product->id . '/local-stock-entry', [
                'entry_type' => 'supplier_purchase',
                'quantity' => 2,
                'list_price' => 100,
                'discount_rate' => 45,
                'calculated_purchase_unit_price' => 55,
                'unit_purchase_price' => 54.5,
                'manual_purchase_unit_price' => '1',
                'vat_enabled' => '0',
                'currency' => 'TL',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tenant_supplier_purchase_entries', [
            'tenant_catalog_product_id' => $product->id,
            'list_price' => 100,
            'discount_rate' => 45,
            'calculated_purchase_unit_price' => 55,
            'unit_purchase_price' => 54.5,
            'manual_purchase_unit_price' => true,
            'payable_amount' => 109,
        ]);
    }

    private function makeCatalogProduct(array $attributes = []): TenantCatalogProduct
    {
        $defaults = [
            'tenant_account_id' => $this->tenant->id,
            'standard_product_id' => null,
            'tenant_sku' => 'SKU-' . uniqid(),
            'name' => $attributes['product_name'] ?? 'Katalog Urunu',
            'product_code' => 'CAT-' . uniqid(),
            'product_name' => 'Katalog Urunu',
            'slug' => 'katalog-urunu-' . uniqid(),
            'standard_category_id' => null,
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

        return TenantCatalogProduct::query()->create(array_merge($defaults, $attributes));
    }
}
