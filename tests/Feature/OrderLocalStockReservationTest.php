<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantLocalStock;
use App\Models\TenantStockReservation;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Services\Stock\TenantLocalStockResolver;
use App\Services\SupplierProcurementRequestService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderLocalStockReservationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

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
    }

    public function test_TenantLocalStockExactVariantResolver_returns_exact_available_quantity(): void
    {
        [$product, $variant] = $this->createCatalogProductWithVariant('ET-0506-MV');
        $this->createExactVariantStock($product, $variant, 1000);

        $resolved = app(TenantLocalStockResolver::class)->resolve(
            $this->tenant->id,
            $product->id,
            $variant->id,
            $variant->variant_code
        );

        $this->assertTrue($resolved['resolved']);
        $this->assertSame('variant', $resolved['scope']);
        $this->assertSame('exact_variant_stock_found', $resolved['reason_code']);
        $this->assertSame(1000.0, (float) $resolved['quantity_on_hand']);
        $this->assertSame(0.0, (float) $resolved['quantity_reserved']);
        $this->assertSame(1000.0, (float) $resolved['quantity_available']);
    }

    public function test_TenantLocalStockAmbiguousProductLevel_refuses_variant_auto_allocation(): void
    {
        [$product, $variant] = $this->createCatalogProductWithVariant('ET-0506-MV');
        $this->createLegacyProductLevelStock($product, 2000);

        $resolved = app(TenantLocalStockResolver::class)->resolve(
            $this->tenant->id,
            $product->id,
            $variant->id,
            $variant->variant_code
        );

        $this->assertFalse($resolved['resolved']);
        $this->assertSame('unresolved', $resolved['scope']);
        $this->assertSame('ambiguous_product_level_stock', $resolved['reason_code']);
        $this->assertSame(0.0, (float) $resolved['quantity_available']);
    }

    public function test_OrderLocalStockPartialReservation_allocates_exact_variant_and_procures_only_shortfall(): void
    {
        $supplierSource = $this->createSupplierSource('ETKIN-LOCAL-PARTIAL');
        [$product, $variant] = $this->createCatalogProductWithVariant('ET-0506-MV');
        $stock = $this->createExactVariantStock($product, $variant, 1000);
        $order = $this->createOrder('SP-LOCAL-PARTIAL');
        $item = $this->createOrderItem($order, $product, $variant, $supplierSource, 1500);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        $procurement = $item->fresh('procurement')->procurement;
        $stock = $stock->fresh();

        $this->assertNotNull($procurement);
        $this->assertTrue((bool) $procurement->requires_procurement);
        $this->assertSame('mixed', $procurement->fulfillment_source);
        $this->assertSame(1500.0, (float) $procurement->requested_quantity);
        $this->assertSame(1000.0, (float) $procurement->local_allocated_quantity);
        $this->assertSame(500.0, (float) $procurement->supplier_requested_quantity);
        $this->assertSame(500.0, (float) $procurement->remaining_quantity);
        $this->assertSame(1000.0, (float) $stock->quantity_reserved);
        $this->assertSame(0.0, (float) $stock->quantity_available);
        $this->assertSame(1000.0, (float) TenantStockReservation::query()->where('order_item_id', $item->id)->where('status', 'active')->sum('quantity'));
    }

    public function test_SupplierRequestUsesProcurementShortfall_creates_request_item_with_remaining_shortfall_only(): void
    {
        $supplierSource = $this->createSupplierSource('ETKIN-SHORTFALL');
        [$product, $variant] = $this->createCatalogProductWithVariant('ET-0506-MV');
        $this->createExactVariantStock($product, $variant, 1000);
        $order = $this->createOrder('SP-LOCAL-SHORTFALL');
        $item = $this->createOrderItem($order, $product, $variant, $supplierSource, 1500);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        $procurement = $item->fresh('procurement')->procurement;
        $request = app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $supplierSource->supplier_id,
            [$procurement->id],
            $this->adminUser
        );

        $requestItem = $request->fresh('items')->items->firstOrFail();

        $this->assertSame(500.0, (float) $procurement->supplier_requested_quantity);
        $this->assertSame(500.0, (float) $procurement->remaining_quantity);
        $this->assertSame(500.0, (float) $requestItem->requested_quantity);
        $this->assertSame(500.0, (float) $requestItem->remaining_quantity);
    }

    public function test_OrderCancellationReleasesStockReservation_releases_active_exact_variant_reservation(): void
    {
        $supplierSource = $this->createSupplierSource('ETKIN-CANCEL');
        [$product, $variant] = $this->createCatalogProductWithVariant('ET-0506-MV');
        $stock = $this->createExactVariantStock($product, $variant, 1000);
        $order = $this->createOrder('SP-LOCAL-CANCEL');
        $item = $this->createOrderItem($order, $product, $variant, $supplierSource, 700);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        $this->assertSame(700.0, (float) TenantStockReservation::query()->where('order_item_id', $item->id)->where('status', 'active')->sum('quantity'));

        $order->status = 'cancelled';
        $order->save();

        $stock = $stock->fresh();

        $this->assertSame(0, TenantStockReservation::query()->where('order_item_id', $item->id)->where('status', 'active')->count());
        $this->assertSame(700.0, (float) TenantStockReservation::query()->where('order_item_id', $item->id)->where('status', 'released')->sum('quantity'));
        $this->assertSame(0.0, (float) $stock->quantity_reserved);
        $this->assertSame(1000.0, (float) $stock->quantity_available);
    }

    private function createOrder(string $documentNumber): Order
    {
        return Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $documentNumber,
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);
    }

    private function createOrderItem(
        Order $order,
        TenantCatalogProduct $product,
        TenantCatalogProductVariant $variant,
        SupplierSource $supplierSource,
        float $quantity,
    ): OrderItem {
        return OrderItem::query()->create([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'tenant_catalog_product_id' => $product->id,
            'tenant_catalog_product_variant_id' => $variant->id,
            'item_type' => 'product',
            'product_source' => 'tenant_catalog',
            'product_name' => $variant->display_name,
            'product_code' => $variant->variant_code,
            'supplier_id' => null,
            'supplier_source_id' => $supplierSource->id,
            'quantity' => $quantity,
            'unit' => 'Adet',
            'description' => 'Local stock reservation test item',
            'product_snapshot' => [
                'tenant_catalog_product_id' => $product->id,
                'tenant_catalog_product_variant_id' => $variant->id,
                'product_code' => $variant->variant_code,
                'product_name' => $variant->display_name,
                'supplier_name' => $supplierSource->supplier->name,
                'catalog_source_label' => 'Tedarikçi Ürünü',
                'visible_in_catalog' => true,
                'visible_in_quote' => true,
            ],
            'price_snapshot' => [
                'unit_price' => 9.2,
                'line_total' => round(9.2 * $quantity, 2),
                'vat_total' => 0,
            ],
            'stock_snapshot' => [
                'local_stock_quantity' => 1000,
                'supplier_stock_quantity' => 27800,
                'visible_stock_quantity' => 1000,
                'safe_stock_quantity' => 0,
                'local_stock_priority' => true,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 9.2,
            'discount_rate' => 0,
            'unit_price' => 9.2,
            'line_total' => round(9.2 * $quantity, 2),
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);
    }

    private function createCatalogProductWithVariant(string $variantCode): array
    {
        $product = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'product_code' => 'ET-0506',
            'tenant_sku' => 'ET-0506',
            'product_name' => 'ET-0506 Plastik Kalem',
            'name' => 'ET-0506 Plastik Kalem',
            'slug' => 'et-0506-plastik-kalem',
            'display_price' => 9.2,
            'sale_price' => 9.2,
            'currency' => 'TL',
            'total_stock_quantity' => 28800,
            'local_stock_quantity' => 2000,
            'supplier_stock_quantity' => 27800,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => [['supplier_id' => 1]],
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'local_stock_priority' => true,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'is_active' => true,
            'meta' => ['is_parent' => true],
        ]);

        $variant = TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'variant_code' => $variantCode,
            'variant_name' => 'Mavi',
            'variant_color' => 'Mavi',
            'display_price' => 9.2,
            'currency' => 'TL',
            'stock_quantity' => 28800,
            'local_stock_quantity' => 1000,
            'supplier_stock_quantity' => 27800,
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => [['supplier_id' => 1]],
            'meta' => ['is_variant' => true],
        ]);

        return [$product, $variant];
    }

    private function createSupplierSource(string $code): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => $code . ' Supplier',
            'code' => $code,
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
        ]);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => $code . ' Source',
            'url' => 'https://example.test/' . strtolower($code),
            'status' => 'active',
        ]);
    }

    private function createExactVariantStock(TenantCatalogProduct $product, TenantCatalogProductVariant $variant, float $quantity): TenantLocalStock
    {
        return TenantLocalStock::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'tenant_catalog_product_variant_id' => $variant->id,
            'stock_scope' => 'variant',
            'warehouse_code' => 'LOCAL-MAIN',
            'location_code' => null,
            'quantity_on_hand' => $quantity,
            'quantity_reserved' => 0,
            'quantity_available' => $quantity,
            'reorder_level' => 0,
            'legacy_assignment_status' => null,
        ]);
    }

    private function createLegacyProductLevelStock(TenantCatalogProduct $product, float $quantity): TenantLocalStock
    {
        return TenantLocalStock::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'tenant_catalog_product_id' => $product->id,
            'tenant_catalog_product_variant_id' => null,
            'stock_scope' => 'product',
            'legacy_assignment_status' => 'legacy_unassigned',
            'warehouse_code' => 'LOCAL-MAIN',
            'location_code' => null,
            'quantity_on_hand' => $quantity,
            'quantity_reserved' => 0,
            'quantity_available' => $quantity,
            'reorder_level' => 0,
        ]);
    }
}
