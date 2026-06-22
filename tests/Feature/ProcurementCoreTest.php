<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\ProcurementCreationService;
use App\Services\ProcurementWorkflowService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementCoreTest extends TestCase
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
    }

    public function test_quote_conversion_creates_procurement_for_each_order_item_and_links_work_forms(): void
    {
        $quote = $this->createQuoteViaHttp();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $order->load(['items.procurement', 'items.workForm', 'procurements']);

        $this->assertCount($order->items->count(), $order->procurements);

        foreach ($order->items as $item) {
            $this->assertNotNull($item->procurement);
            $this->assertNotNull($item->workForm);
            $this->assertSame($item->workForm->id, $item->procurement->work_form_id);
            $this->assertNotEmpty($item->workForm->procurement_snapshot);
        }
    }

    public function test_procurement_defaults_follow_supplier_local_customer_and_not_required_rules(): void
    {
        $supplierSource = $this->createSupplierSource('AKDENIZ-DEFAULTS');
        $order = $this->createOrder('SP-PROC-DEFAULTS');

        $supplierItem = $this->createOrderItem($order, [
            'product_name' => 'Tedarikçi Ürünü',
            'product_code' => 'SUP-001',
            'item_type' => 'product',
            'product_source' => 'supplier_feed',
            'supplier_source_id' => $supplierSource->id,
            'quantity' => 120,
            'stock_snapshot' => [
                'supplier_stock_quantity' => 88,
                'local_stock_quantity' => 0,
                'safe_stock_quantity' => 5,
                'snapshot_taken_at' => '2026-06-13T09:00:00+03:00',
            ],
            'product_snapshot' => [
                'product_name' => 'Tedarikçi Ürünü',
                'product_code' => 'SUP-001',
                'supplier_name' => $supplierSource->supplier->name,
                'group_code' => 'SHOULD-NOT-LEAK',
                'warning_badges' => ['Stok kontrolü gerekli', 'Fiyat uyarısı'],
            ],
        ]);

        $localItem = $this->createOrderItem($order, [
            'product_name' => 'Local Stok Ürünü',
            'product_code' => 'LOC-001',
            'item_type' => 'product',
            'product_source' => 'local_stock',
            'quantity' => 25,
            'stock_snapshot' => [
                'local_stock_quantity' => 14,
                'supplier_stock_quantity' => 0,
                'local_stock_priority' => true,
            ],
        ]);

        $customerItem = $this->createOrderItem($order, [
            'product_name' => 'Müşteri Ürünü',
            'product_code' => 'CUS-001',
            'item_type' => 'customer_supplied_product',
            'product_source' => 'customer_supplied',
            'quantity' => 250,
        ]);

        $serviceItem = $this->createOrderItem($order, [
            'product_name' => 'Kurulum Hizmeti',
            'product_code' => 'SRV-001',
            'item_type' => 'service',
            'product_source' => 'manual',
            'quantity' => 1,
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        $supplierProcurement = $supplierItem->fresh(['procurement.workForm'])->procurement;
        $localProcurement = $localItem->fresh(['procurement.workForm'])->procurement;
        $customerProcurement = $customerItem->fresh(['procurement.workForm'])->procurement;
        $serviceProcurement = $serviceItem->fresh(['procurement.workForm'])->procurement;

        $this->assertSame(OrderItemProcurement::FULFILLMENT_SUPPLIER, $supplierProcurement->fulfillment_source);
        $this->assertSame(OrderItemProcurement::STATUS_PENDING, $supplierProcurement->procurement_status);
        $this->assertSame(120.0, (float) $supplierProcurement->requested_quantity);
        $this->assertSame(120.0, (float) $supplierProcurement->supplier_requested_quantity);
        $this->assertSame(120.0, (float) $supplierProcurement->remaining_quantity);

        $this->assertSame(OrderItemProcurement::FULFILLMENT_LOCAL_STOCK, $localProcurement->fulfillment_source);
        $this->assertSame(0.0, (float) $localProcurement->local_allocated_quantity);
        $this->assertTrue($localProcurement->isLocalStockBased());

        $this->assertSame(OrderItemProcurement::FULFILLMENT_CUSTOMER_SUPPLIED, $customerProcurement->fulfillment_source);
        $this->assertSame(OrderItemProcurement::STATUS_CUSTOMER_WAITING, $customerProcurement->procurement_status);

        $this->assertSame(OrderItemProcurement::FULFILLMENT_NOT_REQUIRED, $serviceProcurement->fulfillment_source);
        $this->assertSame(OrderItemProcurement::STATUS_NOT_REQUIRED, $serviceProcurement->procurement_status);
        $this->assertFalse($serviceProcurement->requires_procurement);

        $this->assertSame(0, StockMovement::query()->count());

        foreach ([$supplierProcurement, $localProcurement, $customerProcurement, $serviceProcurement] as $procurement) {
            $this->assertNotNull($procurement->workForm);
            $this->assertNotEmpty($procurement->workForm->procurement_snapshot);
            $this->assertSame(3, $procurement->workForm->version);
            $this->assertSnapshotHasNoForbiddenKeys($procurement->snapshot);
            $this->assertSnapshotHasNoForbiddenKeys($procurement->procurement_snapshot);
            $this->assertSnapshotHasNoForbiddenKeys($procurement->workForm->procurement_snapshot);
        }

        $this->assertSame('supplier_reference_stock', data_get($supplierProcurement->snapshot, 'stock_handling_mode'));
        $this->assertSame(['Stok kontrolü gerekli'], data_get($supplierProcurement->snapshot, 'warning_labels'));
        $this->assertTrue($supplierProcurement->workForm->activityLogs->contains(
            fn ($log) => $log->action_type === 'procurement_needed'
        ));
        $this->assertTrue($customerProcurement->workForm->activityLogs->contains(
            fn ($log) => $log->action_type === 'customer_supplied_product_waiting'
        ));
        $this->assertTrue($serviceProcurement->workForm->activityLogs->contains(
            fn ($log) => $log->action_type === 'procurement_not_required'
        ));
    }

    public function test_supplier_procurement_workflow_tracks_quantities_without_stock_decrement_or_snapshot_mutation(): void
    {
        $supplierSource = $this->createSupplierSource('AKDENIZ-WORKFLOW');
        $order = $this->createOrder('SP-PROC-WORKFLOW');
        $item = $this->createOrderItem($order, [
            'product_name' => 'Workflow Tedarikçi Ürünü',
            'product_code' => 'SUP-WF-001',
            'item_type' => 'product',
            'product_source' => 'supplier_feed',
            'supplier_source_id' => $supplierSource->id,
            'quantity' => 100,
            'stock_snapshot' => [
                'supplier_stock_quantity' => 42,
                'local_stock_quantity' => 0,
                'safe_stock_quantity' => 0,
                'snapshot_taken_at' => '2026-06-13T10:00:00+03:00',
            ],
            'product_snapshot' => [
                'product_name' => 'Workflow Tedarikçi Ürünü',
                'product_code' => 'SUP-WF-001',
                'supplier_name' => $supplierSource->supplier->name,
                'group_code' => 'PDH-SECRET',
                'raw_mapping' => ['internal' => 'nope'],
            ],
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->first();
        $procurement = $item->fresh(['procurement.workForm'])->procurement;
        $initialVersion = $workForm->fresh()->version;
        $initialStockSnapshot = $item->stock_snapshot;

        $workflow = app(ProcurementWorkflowService::class);
        $workflow->markRequestCreated($procurement, $this->adminUser);
        $workflow->markSupplierOrdered($procurement->fresh(), $this->adminUser);
        $workflow->markPartiallyReceived($procurement->fresh(), 30, $this->adminUser);

        $procurement = $procurement->fresh(['workForm.activityLogs']);
        $this->assertSame(OrderItemProcurement::STATUS_PARTIALLY_RECEIVED, $procurement->procurement_status);
        $this->assertSame(30.0, (float) $procurement->received_quantity);
        $this->assertSame(70.0, (float) $procurement->remaining_quantity);

        $workflow->markFullyReceived($procurement->fresh(), $this->adminUser);

        $procurement = $procurement->fresh(['workForm.activityLogs']);
        $item = $item->fresh();

        $this->assertSame(OrderItemProcurement::STATUS_FULLY_RECEIVED, $procurement->procurement_status);
        $this->assertSame(100.0, (float) $procurement->requested_quantity);
        $this->assertSame(100.0, (float) $procurement->supplier_requested_quantity);
        $this->assertSame(100.0, (float) $procurement->received_quantity);
        $this->assertSame(0.0, (float) $procurement->remaining_quantity);
        $this->assertTrue($procurement->isFullyReceived());
        $this->assertSame($initialStockSnapshot, $item->stock_snapshot);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame($initialVersion + 4, $procurement->workForm->version);
        $this->assertTrue($procurement->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'procurement_request_created'));
        $this->assertTrue($procurement->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'supplier_ordered'));
        $this->assertTrue($procurement->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'procurement_partially_received'));
        $this->assertTrue($procurement->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'procurement_fully_received'));
        $this->assertSnapshotHasNoForbiddenKeys($procurement->snapshot);
        $this->assertSnapshotHasNoForbiddenKeys($procurement->procurement_snapshot);
        $this->assertSnapshotHasNoForbiddenKeys($procurement->workForm->procurement_snapshot);
    }

    public function test_procurement_public_status_labels_follow_customer_safe_mapping(): void
    {
        $supplierSource = $this->createSupplierSource('AKDENIZ-PUBLIC');
        $order = $this->createOrder('SP-PROC-PUBLIC');

        $supplierItem = $this->createOrderItem($order, [
            'product_name' => 'Public Label Supplier',
            'product_code' => 'PUB-SUP-001',
            'item_type' => 'product',
            'product_source' => 'supplier_feed',
            'supplier_source_id' => $supplierSource->id,
            'quantity' => 20,
        ]);

        $customerItem = $this->createOrderItem($order, [
            'product_name' => 'Public Label Customer',
            'product_code' => 'PUB-CUS-001',
            'item_type' => 'customer_supplied_product',
            'product_source' => 'customer_supplied',
            'quantity' => 15,
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        $workflow = app(ProcurementWorkflowService::class);

        $supplierProcurement = $supplierItem->fresh(['procurement.workForm'])->procurement;
        $customerProcurement = $customerItem->fresh(['procurement.workForm'])->procurement;

        $this->assertSame('Ürününüz hazırlanıyor', data_get($supplierProcurement->workForm->procurement_snapshot, 'public_status_label'));
        $this->assertSame('Müşteri ürünü bekleniyor', data_get($customerProcurement->workForm->procurement_snapshot, 'public_status_label'));

        $workflow->markRequestCreated($supplierProcurement->fresh(), $this->adminUser);
        $supplierProcurement = $supplierProcurement->fresh(['workForm']);
        $this->assertSame('Ürün tedarik sürecinde', data_get($supplierProcurement->workForm->procurement_snapshot, 'public_status_label'));

        $workflow->markPartiallyReceived($supplierProcurement->fresh(), 5, $this->adminUser);
        $supplierProcurement = $supplierProcurement->fresh(['workForm']);
        $this->assertSame('Ürünün bir kısmı hazırlandı', data_get($supplierProcurement->workForm->procurement_snapshot, 'public_status_label'));

        $workflow->markFullyReceived($supplierProcurement->fresh(), $this->adminUser);
        $supplierProcurement = $supplierProcurement->fresh(['workForm']);
        $this->assertSame('Ürün üretime hazır', data_get($supplierProcurement->workForm->procurement_snapshot, 'public_status_label'));

        $workflow->markCustomerProductReceived($customerProcurement->fresh(), $this->adminUser);
        $customerProcurement = $customerProcurement->fresh(['workForm']);
        $this->assertSame('Ürün üretime hazır', data_get($customerProcurement->workForm->procurement_snapshot, 'public_status_label'));
    }

    public function test_duplicate_procurement_is_blocked_and_received_quantity_cannot_exceed_requested_quantity(): void
    {
        $supplierSource = $this->createSupplierSource('AKDENIZ-DUPLICATE');
        $order = $this->createOrder('SP-PROC-DUP');
        $item = $this->createOrderItem($order, [
            'product_name' => 'Duplicate Tedarikçi Ürünü',
            'product_code' => 'SUP-DUP-001',
            'item_type' => 'product',
            'product_source' => 'supplier_feed',
            'supplier_source_id' => $supplierSource->id,
            'quantity' => 10,
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->first();
        $creationService = app(ProcurementCreationService::class);
        $first = $creationService->createForOrderItem($item->fresh(), $workForm, $this->adminUser);
        $second = $creationService->createForOrderItem($item->fresh(), $workForm, $this->adminUser);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, OrderItemProcurement::query()->where('order_item_id', $item->id)->count());

        $this->expectException(\InvalidArgumentException::class);
        app(ProcurementWorkflowService::class)->markPartiallyReceived($first->fresh(), 11, $this->adminUser);
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

    private function createOrderItem(Order $order, array $overrides = []): OrderItem
    {
        return OrderItem::query()->create(array_merge([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Procurement Test Ürünü',
            'product_code' => 'PROC-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'description' => 'Procurement test item',
            'product_snapshot' => [
                'product_name' => 'Procurement Test Ürünü',
                'product_code' => 'PROC-001',
                'warning_badges' => [],
            ],
            'price_snapshot' => [
                'unit_price' => 99.9,
                'line_total' => 999,
                'vat_total' => 199.8,
            ],
            'stock_snapshot' => [
                'local_stock_quantity' => 0,
                'supplier_stock_quantity' => 0,
                'safe_stock_quantity' => 0,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 120,
            'discount_rate' => 10,
            'unit_price' => 99.9,
            'line_total' => 999,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ], $overrides));
    }

    private function createSupplierSource(string $code): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => $code . ' Tedarikçi',
            'code' => $code,
            'status' => 'active',
        ]);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => $code . ' Kaynağı',
            'url' => 'https://example.test/' . strtolower($code),
            'status' => 'active',
        ]);
    }

    private function createQuoteViaHttp(): Order
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $this->customer->id,
                'quote_date' => '2026-06-13',
                'valid_until' => '2026-06-20',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Procurement conversion payload',
                'items' => [
                    [
                        'product_name' => 'Procurement Quote Kalem 1',
                        'product_code' => 'PROC-Q-001',
                        'quantity' => '100',
                        'unit' => 'Adet',
                        'list_price' => '8.60',
                        'discount_rate' => '45',
                        'unit_price' => '4.70',
                        'manual_unit_price' => '1',
                        'vat_rate' => '10',
                        'has_print' => '0',
                        'prints' => [],
                    ],
                    [
                        'product_name' => 'Procurement Quote Kalem 2',
                        'product_code' => 'PROC-Q-002',
                        'quantity' => '50',
                        'unit' => 'Adet',
                        'list_price' => '12.00',
                        'discount_rate' => '10',
                        'unit_price' => '10.80',
                        'manual_unit_price' => '0',
                        'vat_rate' => '20',
                        'has_print' => '0',
                        'prints' => [],
                    ],
                ],
            ]);

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();

        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));

        return $quote;
    }

    private function assertSnapshotHasNoForbiddenKeys(mixed $payload): void
    {
        $forbiddenKeys = [
            'unit_price',
            'list_price',
            'discount_rate',
            'line_total',
            'print_unit_price',
            'print_total',
            'subtotal',
            'vat_total',
            'grand_total',
            'product_total',
            'price_snapshot',
            'cost',
            'margin',
            'group_code',
            'raw_mapping',
            'internal_meta',
            'purchase_cost',
            'supplier_order_reference',
        ];

        if (!is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            $this->assertNotContains((string) $key, $forbiddenKeys, "Forbidden key [{$key}] leaked into procurement snapshot.");
            $this->assertSnapshotHasNoForbiddenKeys($value);
        }
    }
}
