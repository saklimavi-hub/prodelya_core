<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\StandardCategory;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantLocalStock;
use App\Models\TenantSupplierAccess;
use App\Models\TenantSupplierPurchaseEntry;
use App\Models\User;
use App\Services\CurrentAccountSyncService;
use App\Services\TenantCatalog\CatalogFastStockActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockPurchaseFlowTest extends TestCase
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

    public function test_stock_purchase_create_page_has_only_two_entry_types(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.stock-purchases.create'));

        $response->assertOk();
        $response->assertSeeText('Satın Alma');
        $response->assertSeeText('Eldeki Mevcut Stok');
        $response->assertDontSeeText('Hızlı Tamamlanmış Satın Alma');
        $response->assertDontSeeText('Hesaplananı kullan');
        $response->assertDontSeeText('Manuel override');
    }

    public function test_catalog_renders_simple_stock_link_without_inline_form(): void
    {
        $supplier = $this->createSupplierAccess('Stok Link Supplier');
        $product = $this->makeCatalogProduct([
            'product_code' => 'LINK-001',
            'product_name' => 'Link Ürünü',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
            'display_price' => 20,
            'currency' => 'TL',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/catalog');

        $response->assertOk();
        $response->assertSee('Stoğa Al', false);
        $response->assertSee('/admin/stock/purchases/create?product=' . $product->id, false);
        $response->assertDontSeeText('Hızlı Tamamlanmış Satın Alma');
        $response->assertDontSeeText('Hesaplananı kullan');
        $response->assertDontSeeText('Manuel override');
    }

    public function test_stock_purchase_create_supports_exact_variant_preselection(): void
    {
        $supplier = $this->createSupplierAccess('Varyant Supplier');
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();

        $product = $this->makeCatalogProduct([
            'product_code' => 'PRE-GRP-01',
            'product_name' => 'Ön Seçimli Grup',
            'standard_category_id' => $category->id,
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
            'visible_in_quote' => false,
            'meta' => ['is_parent' => true, 'is_sellable' => false],
        ]);

        $variant = TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'variant_code' => 'PRE-VR-01',
            'variant_name' => 'Mavi',
            'display_price' => 33.5,
            'currency' => 'USD',
            'stock_quantity' => 10,
            'local_stock_quantity' => 1,
            'supplier_stock_quantity' => 9,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => ['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name],
            'meta' => ['is_sellable' => true, 'price_snapshot' => ['exchange_rate' => 47.125, 'exchange_rate_date' => '2026-07-17']],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/stock/purchases/create?variant=' . $variant->id);

        $response->assertOk();
        $response->assertSee('variant:' . $variant->id, false);
        $response->assertSee('PRE-VR-01 - ' . e($variant->display_name), false);
        $response->assertDontSee('const candidateOptions =', false);
    }

    public function test_stock_purchase_creates_supplier_debit_and_manual_override(): void
    {
        $supplier = $this->createSupplierAccess('Borçlu Supplier');
        $product = $this->makeCatalogProduct([
            'product_code' => 'SP-001',
            'product_name' => 'Satın Alma Ürünü',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
            'display_price' => 10,
            'currency' => 'TL',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/stock/purchases', [
                'entry_type' => 'supplier_purchase',
                'supplier_id' => $supplier->id,
                'entry_date' => '2026-07-17',
                'document_no' => 'SP-001',
                'rows' => [[
                    'selection_key' => 'product:' . $product->id,
                    'quantity' => 2,
                    'list_price' => 10,
                    'discount_rate' => 50,
                    'unit_purchase_price' => 4.5,
                    'currency' => 'TRY',
                    'exchange_rate' => 1,
                ]],
            ]);

        $response->assertRedirect();

        $entry = TenantSupplierPurchaseEntry::query()->where('document_no', 'SP-001')->firstOrFail();
        $this->assertTrue((bool) $entry->manual_override);
        $this->assertSame(9.0, (float) $entry->purchase_total_try);

        $this->assertDatabaseHas('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => CatalogFastStockActionService::SOURCE_TYPE_PURCHASE_DEBIT,
            'source_id' => $entry->id,
            'amount' => 9,
        ]);
    }

    public function test_stock_purchase_opening_stock_creates_stock_only(): void
    {
        $product = $this->makeCatalogProduct([
            'product_code' => 'OPENING-NEW',
            'product_name' => 'Eldeki Stok',
            'display_price' => 30.5,
            'currency' => 'TL',
            'source_summary' => [],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/stock/purchases', [
                'entry_type' => 'existing_stock',
                'entry_date' => '2026-07-17',
                'rows' => [[
                    'selection_key' => 'product:' . $product->id,
                    'quantity' => 3,
                    'currency' => 'TRY',
                    'exchange_rate' => 1,
                ]],
            ]);

        $response->assertRedirect();

        $entry = TenantSupplierPurchaseEntry::query()->latest('id')->firstOrFail();
        $this->assertSame('existing_stock', $entry->entry_type);
        $this->assertSame('none', $entry->payable_status);

        $this->assertDatabaseHas('tenant_local_stocks', [
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'quantity_on_hand' => 3,
        ]);

        $this->assertDatabaseMissing('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => CatalogFastStockActionService::SOURCE_TYPE_PURCHASE_DEBIT,
            'source_id' => $entry->id,
        ]);
    }

    public function test_stock_purchase_supports_multiple_rows(): void
    {
        $supplier = $this->createSupplierAccess('Çoklu Supplier');
        $first = $this->makeCatalogProduct([
            'product_code' => 'MULTI-1',
            'product_name' => 'İlk Ürün',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
            'display_price' => 10,
            'currency' => 'TL',
        ]);
        $second = $this->makeCatalogProduct([
            'product_code' => 'MULTI-2',
            'product_name' => 'İkinci Ürün',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
            'display_price' => 8,
            'currency' => 'TL',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/stock/purchases', [
                'entry_type' => 'supplier_purchase',
                'supplier_id' => $supplier->id,
                'entry_date' => '2026-07-17',
                'document_no' => 'MULTI-001',
                'rows' => [
                    ['selection_key' => 'product:' . $first->id, 'quantity' => 1, 'list_price' => 10, 'discount_rate' => 10, 'unit_purchase_price' => 9, 'currency' => 'TRY', 'exchange_rate' => 1],
                    ['selection_key' => 'product:' . $second->id, 'quantity' => 2, 'list_price' => 8, 'discount_rate' => 0, 'unit_purchase_price' => 8, 'currency' => 'TRY', 'exchange_rate' => 1],
                ],
            ]);

        $response->assertRedirect(route('admin.stock-purchases.index'));
        $this->assertSame(2, TenantSupplierPurchaseEntry::query()->where('document_no', 'MULTI-001')->count());
    }

    public function test_stock_purchase_rejects_parent_product_key(): void
    {
        $category = StandardCategory::query()->where('is_active', true)->whereNotNull('parent_id')->firstOrFail();
        $product = $this->makeCatalogProduct([
            'product_code' => 'PARENT-1',
            'product_name' => 'Parent Ürün',
            'standard_category_id' => $category->id,
            'meta' => ['is_parent' => true, 'is_sellable' => false],
            'visible_in_quote' => false,
        ]);

        TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'variant_code' => 'PARENT-V1',
            'variant_name' => 'V1',
            'display_price' => 12,
            'currency' => 'TL',
            'stock_quantity' => 1,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 1,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => [],
            'meta' => ['is_sellable' => true],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->from('/admin/stock/purchases/create')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/stock/purchases', [
                'entry_type' => 'existing_stock',
                'entry_date' => '2026-07-17',
                'rows' => [[
                    'selection_key' => 'product:' . $product->id,
                    'quantity' => 1,
                    'currency' => 'TRY',
                    'exchange_rate' => 1,
                ]],
            ]);

        $response->assertSessionHasErrors('rows.0.selection_key');
    }

    public function test_stock_purchase_supplier_access_guard(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Kısıtlı Supplier',
            'code' => 'KST-01',
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'visible_in_catalog' => true,
            'can_use_in_quotes' => true,
            'can_request_purchase' => false,
            'price_multiplier' => 1,
            'safe_stock_quantity' => 0,
            'export_allowed' => false,
        ]);

        $product = $this->makeCatalogProduct([
            'product_code' => 'GUARD-01',
            'product_name' => 'Kısıtlı Ürün',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
            'display_price' => 12,
            'currency' => 'TL',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->from('/admin/stock/purchases/create')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/stock/purchases', [
                'entry_type' => 'supplier_purchase',
                'supplier_id' => $supplier->id,
                'entry_date' => '2026-07-17',
                'rows' => [[
                    'selection_key' => 'product:' . $product->id,
                    'quantity' => 1,
                    'list_price' => 12,
                    'discount_rate' => 0,
                    'unit_purchase_price' => 12,
                    'currency' => 'TRY',
                    'exchange_rate' => 1,
                ]],
            ]);

        $response->assertSessionHasErrors('rows.0.selection_key');
    }

    public function test_stock_purchase_cancellation_reverses_stock_and_debit(): void
    {
        $supplier = $this->createSupplierAccess('İptal Supplier');
        $product = $this->makeCatalogProduct([
            'product_code' => 'CANCEL-1',
            'product_name' => 'İptal Ürünü',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
            'display_price' => 10,
            'currency' => 'TL',
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/stock/purchases', [
                'entry_type' => 'supplier_purchase',
                'supplier_id' => $supplier->id,
                'entry_date' => '2026-07-17',
                'document_no' => 'CANCEL-001',
                'rows' => [[
                    'selection_key' => 'product:' . $product->id,
                    'quantity' => 2,
                    'list_price' => 10,
                    'discount_rate' => 50,
                    'unit_purchase_price' => 5,
                    'currency' => 'TRY',
                    'exchange_rate' => 1,
                ]],
            ]);

        $entry = TenantSupplierPurchaseEntry::query()->where('document_no', 'CANCEL-001')->firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/stock/purchases/' . $entry->id . '/cancel', [
                'cancellation_reason' => 'Fixture cancellation',
            ]);

        $response->assertRedirect(route('admin.stock-purchases.show', $entry));

        $this->assertDatabaseHas('tenant_supplier_purchase_entries', [
            'id' => $entry->id,
            'entry_status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('tenant_local_stocks', [
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'quantity_on_hand' => 0,
        ]);
        $this->assertDatabaseHas('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => CatalogFastStockActionService::SOURCE_TYPE_PURCHASE_REVERSAL,
            'source_id' => $entry->id,
        ]);
    }

    public function test_stock_purchase_opening_stock_persists_no_price_and_no_unit_cost(): void
    {
        $product = $this->makeCatalogProduct([
            'product_code' => 'OPENING-FREE',
            'product_name' => 'Maliyetsiz Açılış',
            'display_price' => 224,
            'currency' => 'TL',
            'source_summary' => [],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/stock/purchases', [
                'entry_type' => 'existing_stock',
                'entry_date' => '2026-07-17',
                'rows' => [[
                    'selection_key' => 'product:' . $product->id,
                    'quantity' => 100,
                    'list_price' => 224,
                    'discount_rate' => 54,
                    'unit_purchase_price' => 103.04,
                    'currency' => 'TRY',
                    'exchange_rate' => 1,
                ]],
            ]);

        $response->assertRedirect();

        $entry = TenantSupplierPurchaseEntry::query()->latest('id')->firstOrFail();
        $movement = StockMovement::query()
            ->where('reference_type', TenantSupplierPurchaseEntry::class)
            ->where('reference_id', $entry->id)
            ->firstOrFail();

        $this->assertSame('existing_stock', $entry->entry_type);
        $this->assertSame(0.0, (float) $entry->original_list_price);
        $this->assertSame(0.0, (float) $entry->discount_rate);
        $this->assertSame(0.0, (float) $entry->final_unit_price_original);
        $this->assertSame(0.0, (float) $entry->purchase_total_try);
        $this->assertFalse((bool) $entry->manual_override);
        $this->assertContains((float) $movement->unit_cost, [0.0]);
    }

    public function test_stock_purchase_opening_stock_detail_hides_finance_and_cari_sections(): void
    {
        $product = $this->makeCatalogProduct([
            'product_code' => 'OPENING-SHOW',
            'product_name' => 'Gösterim Açılış',
            'display_price' => 224,
            'currency' => 'TL',
            'source_summary' => [],
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/stock/purchases', [
                'entry_type' => 'existing_stock',
                'entry_date' => '2026-07-17',
                'rows' => [[
                    'selection_key' => 'product:' . $product->id,
                    'quantity' => 3,
                    'currency' => 'TRY',
                    'exchange_rate' => 1,
                ]],
            ]);

        $entry = TenantSupplierPurchaseEntry::query()->latest('id')->firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.stock-purchases.show', $entry));

        $response->assertOk();
        $response->assertDontSeeText('Cari Hareketleri');
        $response->assertDontSeeText('Orijinal liste fiyatı');
        $response->assertDontSeeText('Alış iskontosu');
        $response->assertDontSeeText('Alış birim fiyatı');
        $response->assertDontSeeText('Toplam tutar');
        $response->assertDontSeeText('Belge no');
    }

    public function test_stock_purchase_detail_uses_user_facing_movement_labels(): void
    {
        $product = $this->makeCatalogProduct([
            'product_code' => 'OPENING-LABEL',
            'product_name' => 'Label Açılış',
            'display_price' => 224,
            'currency' => 'TL',
            'source_summary' => [],
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/stock/purchases', [
                'entry_type' => 'existing_stock',
                'entry_date' => '2026-07-17',
                'rows' => [[
                    'selection_key' => 'product:' . $product->id,
                    'quantity' => 1,
                    'currency' => 'TRY',
                    'exchange_rate' => 1,
                ]],
            ]);

        $entry = TenantSupplierPurchaseEntry::query()->latest('id')->firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.stock-purchases.show', $entry));

        $response->assertOk();
        $response->assertSeeText('Stok hareketi');
        $response->assertSeeText('Eldeki Stok Girişi');
        $response->assertSeeText('Ana Depo · LOCAL-MAIN');
        $response->assertDontSeeText('Stock movement');
        $response->assertDontSeeText('adjustment');
    }

    public function test_stock_purchase_final_manual_fixture_calculation_persists_expected_totals(): void
    {
        $supplier = $this->createSupplierAccess('Fixture Supplier');
        $product = $this->makeCatalogProduct([
            'product_code' => 'AK-2420-GRI',
            'product_name' => 'AK-2420 Gri',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
            'display_price' => 224,
            'currency' => 'TL',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/stock/purchases', [
                'entry_type' => 'supplier_purchase',
                'supplier_id' => $supplier->id,
                'entry_date' => '2026-07-17',
                'document_no' => 'FIXTURE-224-54-100',
                'rows' => [[
                    'selection_key' => 'product:' . $product->id,
                    'quantity' => 100,
                    'list_price' => 224,
                    'discount_rate' => 54,
                    'unit_purchase_price' => 103.04,
                    'currency' => 'TRY',
                    'exchange_rate' => 1,
                ]],
            ]);

        $response->assertRedirect();

        $entry = TenantSupplierPurchaseEntry::query()->where('document_no', 'FIXTURE-224-54-100')->firstOrFail();
        $this->assertSame(103.04, (float) $entry->final_unit_price_original);
        $this->assertSame(10304.0, (float) $entry->purchase_total_try);
    }

    public function test_stock_purchase_opening_stock_cancellation_reverses_only_stock(): void
    {
        $product = $this->makeCatalogProduct([
            'product_code' => 'OPEN-CANCEL',
            'product_name' => 'Açılış İptal',
            'display_price' => 224,
            'currency' => 'TL',
            'source_summary' => [],
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/stock/purchases', [
                'entry_type' => 'existing_stock',
                'entry_date' => '2026-07-17',
                'rows' => [[
                    'selection_key' => 'product:' . $product->id,
                    'quantity' => 100,
                    'currency' => 'TRY',
                    'exchange_rate' => 1,
                ]],
            ]);

        $entry = TenantSupplierPurchaseEntry::query()->latest('id')->firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/stock/purchases/' . $entry->id . '/cancel', [
                'cancellation_reason' => 'M10-C2 final manuel smoke',
            ]);

        $response->assertRedirect(route('admin.stock-purchases.show', $entry));

        $this->assertDatabaseHas('tenant_supplier_purchase_entries', [
            'id' => $entry->id,
            'entry_status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('tenant_local_stocks', [
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'quantity_on_hand' => 0,
        ]);
        $this->assertDatabaseMissing('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_id' => $entry->id,
        ]);
        $this->assertSame(2, StockMovement::query()->where('reference_type', TenantSupplierPurchaseEntry::class)->where('reference_id', $entry->id)->count());
    }

    public function test_stock_purchase_quantity_one_create_and_cancel_returns_supplier_balance_to_baseline(): void
    {
        $supplier = $this->createSupplierAccess('Qty One Supplier');
        $product = $this->makeCatalogProduct([
            'product_code' => 'QTY1-ITEM',
            'product_name' => 'Tek Adet Satın Alma',
            'source_summary' => [['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name]],
            'display_price' => 224,
            'currency' => 'TL',
        ]);

        $baselineCount = CurrentAccountTransaction::query()->count();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/stock/purchases', [
                'entry_type' => 'supplier_purchase',
                'supplier_id' => $supplier->id,
                'entry_date' => '2026-07-17',
                'document_no' => 'QTY-ONE-CANCEL',
                'rows' => [[
                    'selection_key' => 'product:' . $product->id,
                    'quantity' => 1,
                    'list_price' => 224,
                    'discount_rate' => 54,
                    'unit_purchase_price' => 103.04,
                    'currency' => 'TRY',
                    'exchange_rate' => 1,
                ]],
            ]);

        $entry = TenantSupplierPurchaseEntry::query()->where('document_no', 'QTY-ONE-CANCEL')->firstOrFail();
        $this->assertDatabaseHas('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => CatalogFastStockActionService::SOURCE_TYPE_PURCHASE_DEBIT,
            'source_id' => $entry->id,
            'amount' => 103.04,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/stock/purchases/' . $entry->id . '/cancel', [
                'cancellation_reason' => 'Quantity one manual proof',
            ])
            ->assertRedirect(route('admin.stock-purchases.show', $entry));

        $this->assertDatabaseHas('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => CatalogFastStockActionService::SOURCE_TYPE_PURCHASE_REVERSAL,
            'source_id' => $entry->id,
            'amount' => 103.04,
        ]);
        $this->assertSame($baselineCount + 2, CurrentAccountTransaction::query()->count());
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
