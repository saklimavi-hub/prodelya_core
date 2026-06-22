<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProcurement;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierProcurementRequest;
use App\Models\SupplierProcurementRequestItem;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Services\ProcurementWorkflowService;
use App\Services\PublicWorkFormTrackingDataBuilder;
use App\Services\SupplierProcurementRequestDataBuilder;
use App\Services\SupplierProcurementRequestService;
use App\Services\WorkFormCreationService;
use App\Services\WorkFormRenderDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplierProcurementRequestCoreTest extends TestCase
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

    public function test_supplier_procurement_request_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('supplier_procurement_requests'));
        $this->assertTrue(Schema::hasTable('supplier_procurement_request_items'));
    }

    public function test_request_and_item_models_define_core_relationships(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-MODEL');
        $procurement = $this->createSupplierProcurement($supplier, $source, 'SP-SPR-MODEL');

        $request = SupplierProcurementRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'request_number' => 'TS-2026-0001',
            'request_date' => '2026-06-13',
            'status' => SupplierProcurementRequest::STATUS_DRAFT,
            'created_by' => $this->adminUser->id,
        ]);

        $item = SupplierProcurementRequestItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_procurement_request_id' => $request->id,
            'order_item_procurement_id' => $procurement->id,
            'order_id' => $procurement->order_id,
            'order_item_id' => $procurement->order_item_id,
            'work_form_id' => $procurement->work_form_id,
            'supplier_source_id' => $source->id,
            'product_code' => 'SPR-MODEL-001',
            'product_name' => 'Model Test Ürünü',
            'requested_quantity' => 100,
            'unit' => 'Adet',
            'received_quantity' => 10,
            'remaining_quantity' => 90,
        ]);

        $this->assertSame($supplier->id, $request->supplier->id);
        $this->assertSame($item->id, $request->items->first()->id);
        $this->assertSame($procurement->id, $item->procurement->id);
        $this->assertTrue($request->isDraft());
        $this->assertSame('Taslak', $request->safeStatusLabel());
        $this->assertTrue($item->hasPurchasePrice() === false);
        $this->assertSame('Adet', $item->safeUnitLabel());
    }

    public function test_supplier_groups_are_built_only_from_open_accessible_procurements(): void
    {
        [$supplierA, $sourceA] = $this->createSupplierWithAccess('SPR-GRP-A');
        [$supplierB, $sourceB] = $this->createSupplierWithAccess('SPR-GRP-B');
        [$noAccessSupplier, $noAccessSource] = $this->createSupplierWithoutAccess('SPR-GRP-X');
        $this->createSupplierWithAccess('SPR-GRP-NOOPEN');

        $first = $this->createSupplierProcurement($supplierA, $sourceA, 'SP-SPR-GRP-001', ['quantity' => 100]);
        $second = $this->createSupplierProcurement($supplierA, $sourceA, 'SP-SPR-GRP-002', ['quantity' => 50]);
        $third = $this->createSupplierProcurement($supplierB, $sourceB, 'SP-SPR-GRP-003', ['quantity' => 75]);
        $hidden = $this->createSupplierProcurement($noAccessSupplier, $noAccessSource, 'SP-SPR-GRP-004', ['quantity' => 30]);

        $hidden->forceFill(['procurement_status' => OrderItemProcurement::STATUS_PENDING])->save();

        $builder = app(SupplierProcurementRequestDataBuilder::class);
        $groups = collect($builder->buildSupplierGroups($this->tenant))->keyBy('supplier_id');

        $this->assertCount(2, $groups);
        $this->assertTrue($groups->has($supplierA->id));
        $this->assertTrue($groups->has($supplierB->id));
        $this->assertFalse($groups->has($noAccessSupplier->id));
        $this->assertSame(2, data_get($groups[$supplierA->id], 'open_item_count'));
        $this->assertSame(1, data_get($groups[$supplierB->id], 'open_item_count'));
        $this->assertSame(150.0, (float) data_get($groups[$supplierA->id], 'total_missing_quantity'));
        $this->assertSame(75.0, (float) data_get($groups[$supplierB->id], 'total_missing_quantity'));
        $this->assertNotNull($first->fresh()->supplier);
        $this->assertNotNull($second->fresh()->supplier);
        $this->assertNotNull($third->fresh()->supplier);
    }

    public function test_create_draft_groups_same_supplier_procurements_and_blocks_mixed_suppliers(): void
    {
        [$supplierA, $sourceA] = $this->createSupplierWithAccess('SPR-CRT-A');
        [$supplierB, $sourceB] = $this->createSupplierWithAccess('SPR-CRT-B');

        $first = $this->createSupplierProcurement($supplierA, $sourceA, 'SP-SPR-CRT-001', ['quantity' => 100]);
        $second = $this->createSupplierProcurement($supplierA, $sourceA, 'SP-SPR-CRT-002', ['quantity' => 40]);
        $other = $this->createSupplierProcurement($supplierB, $sourceB, 'SP-SPR-CRT-003', ['quantity' => 25]);

        $service = app(SupplierProcurementRequestService::class);
        $request = $service->createDraftForSupplier($this->tenant, $supplierA->id, [$first->id, $second->id], $this->adminUser);

        $this->assertSame($supplierA->id, $request->supplier_id);
        $this->assertTrue($request->isDraft());
        $this->assertMatchesRegularExpression('/^TS-2026-\d{4}$/', $request->request_number);
        $this->assertCount(2, $request->items);
        $this->assertSame(100.0, (float) $request->items->firstWhere('order_item_procurement_id', $first->id)->requested_quantity);

        $this->expectException(\InvalidArgumentException::class);
        $service->createDraftForSupplier($this->tenant, $supplierA->id, [$first->id, $other->id], $this->adminUser);
    }

    public function test_procurement_in_open_request_is_removed_from_candidate_list(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-CANDIDATE');
        $first = $this->createSupplierProcurement($supplier, $source, 'SP-SPR-CAND-001', ['quantity' => 60]);
        $second = $this->createSupplierProcurement($supplier, $source, 'SP-SPR-CAND-002', ['quantity' => 30]);

        $service = app(SupplierProcurementRequestService::class);
        $service->createDraftForSupplier($this->tenant, $supplier->id, [$first->id], $this->adminUser);

        $candidates = app(SupplierProcurementRequestDataBuilder::class)
            ->getCandidateProcurementsForSupplier($this->tenant, $supplier->id);

        $this->assertFalse($candidates->contains('id', $first->id));
        $this->assertTrue($candidates->contains('id', $second->id));
    }

    public function test_request_item_totals_are_calculated_from_purchase_price_and_discount(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-PRICE');
        $procurement = $this->createSupplierProcurement($supplier, $source, 'SP-SPR-PRICE-001', ['quantity' => 100]);

        $service = app(SupplierProcurementRequestService::class);
        $request = $service->createDraftForSupplier($this->tenant, $supplier->id, [$procurement->id], $this->adminUser);
        $item = $request->items->first();

        $updated = $service->updateRequestItems($request, [[
            'id' => $item->id,
            'purchase_list_price' => 9.20,
            'discount_rate' => 45,
            'requested_quantity' => 100,
            'received_quantity' => 0,
            'note' => 'Acil tedarik',
        ]], $this->adminUser);

        $item = $updated->items->first();

        $this->assertSame(100.0, (float) $item->requested_quantity);
        $this->assertSame(5.06, (float) $item->purchase_unit_price);
        $this->assertSame(506.0, (float) $item->purchase_total);
        $this->assertSame(100.0, (float) $item->remaining_quantity);
    }

    public function test_mark_requested_and_supplier_ordered_use_procurement_workflow_service(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-WORKFLOW');
        $procurement = $this->createSupplierProcurement($supplier, $source, 'SP-SPR-WF-001', ['quantity' => 80]);

        $service = app(SupplierProcurementRequestService::class);
        $request = $service->createDraftForSupplier($this->tenant, $supplier->id, [$procurement->id], $this->adminUser);

        $request = $service->markRequested($request, $this->adminUser);
        $procurement = $procurement->fresh(['workForm.activityLogs']);

        $this->assertTrue($request->fresh()->isRequested());
        $this->assertSame(OrderItemProcurement::STATUS_REQUEST_CREATED, $procurement->procurement_status);
        $this->assertTrue($procurement->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'procurement_request_created'));

        $request = $service->markSupplierOrdered($request->fresh(), $this->adminUser);
        $procurement = $procurement->fresh(['workForm.activityLogs']);

        $this->assertTrue($request->fresh()->isSupplierOrdered());
        $this->assertSame(OrderItemProcurement::STATUS_SUPPLIER_ORDERED, $procurement->procurement_status);
        $this->assertTrue($procurement->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'supplier_ordered'));
    }

    public function test_print_data_omits_prices_and_work_form_public_data_do_not_leak_supplier_purchase_prices(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-PRINT');
        $procurement = $this->createSupplierProcurement($supplier, $source, 'SP-SPR-PRINT-001', ['quantity' => 25]);

        $service = app(SupplierProcurementRequestService::class);
        $request = $service->createDraftForSupplier($this->tenant, $supplier->id, [$procurement->id], $this->adminUser);
        $item = $request->items->first();

        $request = $service->updateRequestItems($request, [[
            'id' => $item->id,
            'purchase_list_price' => 12.40,
            'discount_rate' => 10,
            'requested_quantity' => 25,
            'received_quantity' => 0,
            'note' => 'İç maliyet notu',
        ]], $this->adminUser);

        $printData = app(SupplierProcurementRequestDataBuilder::class)->buildPrintData($request->fresh(['supplier', 'items.order']));
        $renderData = app(WorkFormRenderDataBuilder::class)->build($procurement->fresh('workForm')->workForm);
        $publicData = app(PublicWorkFormTrackingDataBuilder::class)->build($procurement->fresh('workForm')->workForm);

        $serializedPrint = json_encode($printData, JSON_UNESCAPED_UNICODE);
        $serializedRender = json_encode($renderData, JSON_UNESCAPED_UNICODE);
        $serializedPublic = json_encode($publicData, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('SP-SPR-PRINT-001', $serializedPrint);
        $this->assertStringContainsString('SPR-PRINT-001', $serializedPrint);
        $this->assertStringContainsString('İç maliyet notu', $serializedPrint);
        $this->assertStringNotContainsString('purchase_list_price', $serializedPrint);
        $this->assertStringNotContainsString('purchase_unit_price', $serializedPrint);
        $this->assertStringNotContainsString('purchase_total', $serializedPrint);
        $this->assertStringNotContainsString('discount_rate', $serializedPrint);
        $this->assertStringNotContainsString('group_code', $serializedPrint);
        $this->assertStringNotContainsString('price_snapshot', $serializedPrint);
        $this->assertStringNotContainsString('12.4', $serializedRender);
        $this->assertStringNotContainsString('12.4', $serializedPublic);
        $this->assertStringNotContainsString('11.16', $serializedRender);
        $this->assertStringNotContainsString('11.16', $serializedPublic);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_no_print_supplier_based_procurements_can_still_be_grouped(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPR-NOPRINT');
        $procurement = $this->createSupplierProcurement($supplier, $source, 'SP-SPR-NOPRINT-001', [
            'quantity' => 120,
            'has_print' => false,
        ]);

        $groups = collect(app(SupplierProcurementRequestDataBuilder::class)->buildSupplierGroups($this->tenant));

        $this->assertTrue($groups->contains(fn (array $group) => (int) $group['supplier_id'] === $supplier->id));
        $this->assertSame(OrderItemProcurement::FULFILLMENT_SUPPLIER, $procurement->fulfillment_source);
    }

    private function createSupplierWithAccess(string $code): array
    {
        $supplier = Supplier::query()->create([
            'name' => $code . ' Tedarikçi',
            'code' => $code,
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'supplier_id' => $supplier->id,
            ],
            [
                'is_active' => true,
                'can_view_products' => true,
                'can_request_purchase' => true,
                'can_use_in_quotes' => true,
                'visible_in_catalog' => true,
                'export_allowed' => false,
                'granted_at' => now(),
            ]
        );

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => $code . ' Kaynağı',
            'url' => 'https://example.test/' . strtolower($code),
            'status' => 'active',
        ]);

        return [$supplier, $source];
    }

    private function createSupplierWithoutAccess(string $code): array
    {
        $supplier = Supplier::query()->create([
            'name' => $code . ' Tedarikçi',
            'code' => $code,
            'status' => 'active',
        ]);

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => $code . ' Kaynağı',
            'url' => 'https://example.test/' . strtolower($code),
            'status' => 'active',
        ]);

        return [$supplier, $source];
    }

    private function createSupplierProcurement(Supplier $supplier, SupplierSource $source, string $orderNumber, array $overrides = []): OrderItemProcurement
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $orderNumber,
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'supplier_feed',
            'product_name' => $overrides['product_name'] ?? ($source->supplier->code . ' Ürün'),
            'product_code' => $overrides['product_code'] ?? ($source->supplier->code . '-001'),
            'supplier_id' => null,
            'supplier_source_id' => $source->id,
            'quantity' => $overrides['quantity'] ?? 100,
            'unit' => $overrides['unit'] ?? 'Adet',
            'description' => 'Supplier procurement test item',
            'product_snapshot' => [
                'product_name' => $overrides['product_name'] ?? ($source->supplier->code . ' Ürün'),
                'product_code' => $overrides['product_code'] ?? ($source->supplier->code . '-001'),
                'supplier_name' => $supplier->name,
                'warning_badges' => [],
            ],
            'price_snapshot' => [
                'unit_price' => 19.90,
                'line_total' => 1990,
                'vat_total' => 398,
            ],
            'stock_snapshot' => [
                'local_stock_quantity' => 0,
                'supplier_stock_quantity' => 50,
                'safe_stock_quantity' => 0,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 25,
            'discount_rate' => 10,
            'unit_price' => 19.90,
            'line_total' => 1990,
            'has_print' => $overrides['has_print'] ?? false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        return $item->fresh(['procurement.workForm'])->procurement;
    }
}
