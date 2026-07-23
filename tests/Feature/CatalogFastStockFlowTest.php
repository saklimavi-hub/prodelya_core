<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\StandardCategory;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantLocalStock;
use App\Models\TenantSupplierAccess;
use App\Models\TenantSupplierPurchaseEntry;
use App\Models\User;
use App\Services\TenantCatalog\CatalogFastStockActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CatalogFastStockFlowTest extends TestCase
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

    public function test_supplier_stock_surface_uses_canonical_catalog_view(): void
    {
        $supplier = $this->createSupplierAccess('Canon Tedarik');
        $product = $this->makeCatalogProduct([
            'product_code' => 'CANON-001',
            'product_name' => 'Canonical Stok Ürünü',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 40,
        ]);

        TenantLocalStock::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'tenant_catalog_product_variant_id' => null,
            'stock_scope' => 'product',
            'warehouse_code' => 'LOCAL-MAIN',
            'location_code' => null,
            'quantity_on_hand' => 7,
            'quantity_reserved' => 0,
            'quantity_available' => 7,
            'reorder_level' => 0,
            'max_stock' => null,
            'last_counted_at' => now(),
            'notes' => 'fixture',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog/local-products/supplier-stock');

        $response->assertRedirect('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $followed = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog?source_type=supplier&stock_state=local_stock');

        $followed->assertOk();
        $followed->assertSeeText('Canonical Stok Ürünü');
        $followed->assertSeeText('7');
    }

    public function test_completed_purchase_creates_exact_stock_movement_and_supplier_debit(): void
    {
        $supplier = $this->createSupplierAccess('Borçlu Supplier');
        $product = $this->makeCatalogProduct([
            'product_code' => 'FAST-PURCHASE-1',
            'product_name' => 'Hızlı Satın Alma Ürünü',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
            'display_price' => 10,
            'currency' => 'TL',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/catalog/' . $product->id . '/local-stock-entry', [
                'entry_type' => 'supplier_purchase',
                'quantity' => 2,
                'list_price' => 10,
                'discount_rate' => 50,
                'calculated_purchase_unit_price' => 5,
                'unit_purchase_price' => 4.5,
                'manual_purchase_unit_price' => '1',
                'currency' => 'TL',
                'exchange_rate' => 1,
                'document_no' => 'FAST-001',
            ]);

        $response->assertRedirect();

        /** @var TenantSupplierPurchaseEntry $entry */
        $entry = TenantSupplierPurchaseEntry::query()->where('document_no', 'FAST-001')->firstOrFail();

        $this->assertSame('supplier_purchase', $entry->entry_type);
        $this->assertSame('TRY', $entry->currency);
        $this->assertSame(9.0, (float) $entry->purchase_total_try);

        $this->assertDatabaseHas('tenant_local_stocks', [
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'tenant_catalog_product_variant_id' => null,
            'quantity_on_hand' => 2,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'reference_type' => TenantSupplierPurchaseEntry::class,
            'reference_id' => $entry->id,
            'reason' => 'purchase',
        ]);

        $this->assertDatabaseHas('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => CatalogFastStockActionService::SOURCE_TYPE_PURCHASE_DEBIT,
            'source_id' => $entry->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            'amount' => 9,
            'currency' => 'TRY',
        ]);
    }

    public function test_opening_stock_does_not_create_supplier_debt_and_can_cancel(): void
    {
        $product = $this->makeCatalogProduct([
            'product_code' => 'OPENING-001',
            'product_name' => 'Eldeki Stok Ürünü',
            'display_price' => 30.5,
            'currency' => 'TL',
        ]);

        $store = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/catalog/' . $product->id . '/local-stock-entry', [
                'entry_type' => 'existing_stock',
                'quantity' => 3,
                'currency' => 'TL',
                'exchange_rate' => 1,
                'document_no' => 'OPEN-001',
            ]);

        $store->assertRedirect();

        $entry = TenantSupplierPurchaseEntry::query()->where('document_no', 'OPEN-001')->firstOrFail();
        $this->assertSame('none', $entry->payable_status);

        $this->assertDatabaseMissing('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => CatalogFastStockActionService::SOURCE_TYPE_PURCHASE_DEBIT,
            'source_id' => $entry->id,
        ]);

        $cancel = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/catalog/stock-entries/' . $entry->id . '/cancel', [
                'cancellation_reason' => 'Fixture reversal',
            ]);

        $cancel->assertRedirect();

        $this->assertDatabaseHas('tenant_supplier_purchase_entries', [
            'id' => $entry->id,
            'entry_status' => 'cancelled',
        ]);

        $this->assertDatabaseHas('tenant_local_stocks', [
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'quantity_on_hand' => 0,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'reference_type' => TenantSupplierPurchaseEntry::class,
            'reference_id' => $entry->id,
            'reason' => 'adjustment',
        ]);
    }

    public function test_variant_required_for_varianted_product(): void
    {
        $supplier = $this->createSupplierAccess('Variant Supplier');
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();

        $product = $this->makeCatalogProduct([
            'product_code' => 'VARIANT-GRP-01',
            'product_name' => 'Varyantlı Ürün',
            'standard_category_id' => $category->id,
            'source_summary' => [[
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'supplier_product_code' => 'VARIANT-GRP-01',
            ]],
            'visible_in_quote' => false,
            'meta' => [
                'is_parent' => true,
                'is_sellable' => false,
                'price_snapshot' => ['list_price' => 100],
            ],
        ]);

        TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'variant_code' => 'VAR-001',
            'variant_name' => 'Varyant 1',
            'display_price' => 100,
            'currency' => 'TL',
            'stock_quantity' => 10,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 10,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => [
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
            ],
            'meta' => [
                'is_variant' => true,
                'is_sellable' => true,
                'quote_search_visible' => true,
            ],
        ]);

        $this->withoutExceptionHandling();
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Grup ürünler local stoğa alınamaz. Lütfen satılabilir varyant ürünü seçin.');

        $this->actingAs($this->adminUser)
            ->from('/admin/catalog')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/catalog/' . $product->id . '/local-stock-entry', [
                'entry_type' => 'existing_stock',
                'quantity' => 1,
                'currency' => 'TL',
                'exchange_rate' => 1,
            ]);
    }

    private function createSupplierAccess(string $name): Supplier
    {
        $supplier = Supplier::query()->create([
            'name' => $name,
            'code' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 8)) . '-' . random_int(10, 99),
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

    private function makeCatalogProduct(array $attributes = []): TenantCatalogProduct
    {
        $defaults = [
            'tenant_account_id' => $this->tenant->id,
            'standard_product_id' => null,
            'tenant_sku' => 'SKU-' . uniqid(),
            'name' => $attributes['product_name'] ?? 'Katalog Ürünü',
            'product_code' => 'CAT-' . uniqid(),
            'product_name' => 'Katalog Ürünü',
            'slug' => 'catalog-' . uniqid(),
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
