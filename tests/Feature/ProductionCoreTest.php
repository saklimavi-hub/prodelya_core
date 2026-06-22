<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\OrderItemPrintGraphicWorkflowService;
use App\Services\ProductionCreationService;
use App\Services\ProductionWorkflowService;
use App\Services\WorkFormAttachmentService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductionCoreTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $adminUser;
    private TenantAccount $tenant;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_work_form_creation_creates_one_production_operation_per_print_line(): void
    {
        $partner = $this->createPartnerCompany();
        $order = $this->createOrder('SP-PROD-001');
        $item = $this->createOrderItem($order, [
            'product_name' => 'Üretim Test Ürünü',
            'product_code' => 'PROD-001',
            'quantity' => 100,
            'has_print' => true,
        ]);

        $firstPrint = $this->createPrint($order, $item, [
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'production_type' => 'İç üretim',
            'print_quantity' => 100,
        ]);

        $secondPrint = $this->createPrint($order, $item, [
            'print_type' => 'Sıcak Baskı',
            'print_option' => 'Logo',
            'production_type' => 'Dış üretim / Fason',
            'subcontractor_company_id' => $partner->id,
            'cliche_status' => 'bekleniyor',
            'print_quantity' => 100,
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->first();

        $firstProduction = $firstPrint->fresh()->production;
        $secondProduction = $secondPrint->fresh()->production;

        $this->assertNotNull($firstProduction);
        $this->assertNotNull($secondProduction);
        $this->assertSame($workForm->id, $firstProduction->work_form_id);
        $this->assertSame($workForm->id, $secondProduction->work_form_id);
        $this->assertSame(100.0, (float) $firstProduction->planned_quantity);
        $this->assertSame(0.0, (float) $firstProduction->completed_quantity);
        $this->assertSame(100.0, (float) $firstProduction->remaining_quantity);
        $this->assertSame(OrderItemPrintProduction::STATUS_PENDING, $firstProduction->production_status);
        $this->assertSame(OrderItemPrintProduction::QC_WAITING, $firstProduction->qc_status);
        $this->assertSame(OrderItemPrintProduction::TYPE_INTERNAL, $firstProduction->production_type);
        $this->assertSame(OrderItemPrintProduction::TYPE_OUTSOURCED, $secondProduction->production_type);
        $this->assertSame($partner->id, $secondProduction->production_company_id);
        $this->assertSame(OrderItemPrintProduction::CLICHE_WAITING, $secondProduction->cliche_status);
        $this->assertSame(5, $workForm->fresh()->version);
        $this->assertNotEmpty($workForm->fresh()->production_snapshot);
        $this->assertTrue($workForm->fresh()->activityLogs->contains(fn ($log) => $log->action_type === 'production_operation_created'));
        $this->assertSnapshotHasNoForbiddenKeys($firstProduction->fresh()->production_snapshot);
        $this->assertSnapshotHasNoForbiddenKeys($workForm->fresh()->production_snapshot);
    }

    public function test_duplicate_production_operation_is_blocked_and_creation_service_returns_existing_record(): void
    {
        $order = $this->createOrder('SP-PROD-002');
        $item = $this->createOrderItem($order, ['has_print' => true]);
        $print = $this->createPrint($order, $item, [
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_quantity' => 25,
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->first();
        $service = app(ProductionCreationService::class);

        $first = $service->createForOrderItemPrint($print->fresh(), $workForm, $this->adminUser);
        $second = $service->createForOrderItemPrint($print->fresh(), $workForm, $this->adminUser);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, OrderItemPrintProduction::query()->where('order_item_print_id', $print->id)->count());
    }

    public function test_production_workflow_updates_statuses_quantities_and_work_form_snapshot_safely(): void
    {
        $partner = $this->createPartnerCompany();
        $order = $this->createOrder('SP-PROD-003');
        $item = $this->createOrderItem($order, [
            'product_name' => 'Riskli Üretim Ürünü',
            'product_code' => 'PROD-003',
            'quantity' => 100,
            'has_print' => true,
        ]);

        $print = $this->createPrint($order, $item, [
            'print_type' => 'Sıcak Baskı',
            'print_option' => 'Logo',
            'production_type' => 'Dış üretim / Fason',
            'subcontractor_company_id' => $partner->id,
            'cliche_status' => OrderItemPrintProduction::CLICHE_NOT_REQUIRED,
            'print_quantity' => 100,
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->first();
        $production = $print->fresh()->production->fresh(['workForm.activityLogs', 'productionCompany']);
        $this->prepareProductionForStart($production, 'core-prod-003-ready.jpg');
        $production = $production->fresh(['workForm.activityLogs', 'productionCompany']);
        $initialVersion = $workForm->fresh()->version;

        $workflow = app(ProductionWorkflowService::class);
        $workflow->assignExternal($production, $partner, $this->adminUser, 'Fason firmaya yönlendirildi.');
        $workflow->markSentToSubcontractor($production->fresh(), $this->adminUser);
        $workflow->markReturnedFromSubcontractor($production->fresh(), $this->adminUser);
        $workflow->markQcStarted($production->fresh(), $this->adminUser);
        $workflow->markQcPassed($production->fresh(), $this->adminUser);
        $workflow->markCompleted($production->fresh(), $this->adminUser);

        $production = $production->fresh(['workForm.activityLogs', 'productionCompany']);

        $this->assertSame(OrderItemPrintProduction::TYPE_OUTSOURCED, $production->production_type);
        $this->assertSame($partner->id, $production->production_company_id);
        $this->assertSame(OrderItemPrintProduction::STATUS_COMPLETED, $production->production_status);
        $this->assertSame(OrderItemPrintProduction::QC_OK, $production->qc_status);
        $this->assertSame(100.0, (float) $production->completed_quantity);
        $this->assertSame(0.0, (float) $production->remaining_quantity);
        $this->assertSame($initialVersion + 6, $production->workForm->version);
        $this->assertSame('Üretim tamamlandı', data_get($production->workForm->production_snapshot, 'public_status_label'));
        $this->assertTrue($production->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_assigned_external'));
        $this->assertTrue($production->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_sent_to_subcontractor'));
        $this->assertTrue($production->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_returned_from_subcontractor'));
        $this->assertTrue($production->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_qc_started'));
        $this->assertTrue($production->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_qc_passed'));
        $this->assertTrue($production->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'production_completed'));
        $this->assertSnapshotHasNoForbiddenKeys($production->production_snapshot);
        $this->assertSnapshotHasNoForbiddenKeys($production->workForm->production_snapshot);
    }

    public function test_assign_internal_and_completed_quantity_guard_work_as_expected(): void
    {
        $order = $this->createOrder('SP-PROD-004');
        $item = $this->createOrderItem($order, ['has_print' => true]);
        $print = $this->createPrint($order, $item, [
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_quantity' => 40,
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);
        $production = $print->fresh()->production;
        $this->prepareProductionForStart($production, 'core-prod-004-ready.jpg');

        $workflow = app(ProductionWorkflowService::class);
        $workflow->assignInternal($production->fresh(), $this->adminUser, 'UV Hattı 1', 'İç hatta alındı');
        $production = $production->fresh();

        $this->assertSame(OrderItemPrintProduction::TYPE_INTERNAL, $production->production_type);
        $this->assertSame('UV Hattı 1', $production->production_unit_name);

        $this->expectException(\InvalidArgumentException::class);
        $workflow->markCompleted($production->fresh(), $this->adminUser, null, 50);
    }

    public function test_readiness_warnings_are_generated_for_graphic_and_procurement_states(): void
    {
        $order = $this->createOrder('SP-PROD-005');
        $item = $this->createOrderItem($order, [
            'item_type' => 'customer_supplied_product',
            'product_source' => 'customer_supplied',
            'has_print' => true,
            'quantity' => 60,
        ]);

        $print = $this->createPrint($order, $item, [
            'print_type' => 'Sıcak Baskı',
            'print_option' => 'Logo',
            'print_quantity' => 60,
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->first()->fresh();
        $production = $print->fresh()->production->fresh();

        $warnings = data_get($production->production_snapshot, 'readiness_warnings', []);

        $this->assertContains('Grafik henüz üretime hazır değil.', $warnings);
        $this->assertContains('Müşteri ürünü bekleniyor.', $warnings);
        $this->assertContains('Tedarik süreci tamamlanmadı.', $warnings);
        $this->assertNotNull($workForm->production_snapshot);
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
            'product_name' => 'Production Test Ürünü',
            'product_code' => 'PROD-BASE-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'description' => 'Production test item',
            'product_snapshot' => [
                'product_name' => 'Production Test Ürünü',
                'product_code' => 'PROD-BASE-001',
                'warning_badges' => [],
                'group_code' => 'HIDDEN-GROUP',
                'raw_mapping' => ['secret' => 'value'],
            ],
            'price_snapshot' => [
                'unit_price' => 99.9,
                'line_total' => 999,
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

    private function createPrint(Order $order, OrderItem $item, array $overrides = []): OrderItemPrint
    {
        return OrderItemPrint::query()->create(array_merge([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_location' => 'Gövde',
            'production_type' => null,
            'subcontractor_company_id' => null,
            'print_color' => 'Tek Renk',
            'print_size' => 'Standart',
            'cliche_status' => null,
            'print_quantity' => $item->quantity,
            'print_unit_price' => 5,
            'print_total' => 50,
            'note' => 'Test baskı',
            'production_note' => null,
            'status' => 'draft',
        ], $overrides));
    }

    private function createPartnerCompany(): Company
    {
        return Company::query()
            ->where('status', 'active')
            ->whereKeyNot($this->customer->id)
            ->orderBy('id')
            ->firstOrFail();
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
            'price_snapshot',
            'group_code',
            'raw_mapping',
            'production_cost',
            'outsource_cost',
            'margin',
            'kdv',
        ];

        if (!is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            $this->assertNotContains((string) $key, $forbiddenKeys, "Forbidden key [{$key}] leaked into production snapshot.");
            $this->assertSnapshotHasNoForbiddenKeys($value);
        }
    }

    private function prepareProductionForStart(OrderItemPrintProduction $production, string $fileName): void
    {
        $production = $production->fresh(['workForm.procurement', 'orderItemPrint.graphicOperation']);

        /** @var OrderItemPrintGraphic $graphic */
        $graphic = $production->orderItemPrint?->graphicOperation;
        $this->assertNotNull($graphic);

        app(WorkFormAttachmentService::class)->attachGraphicVisualToPrintGraphic(
            $graphic,
            UploadedFile::fake()->image($fileName),
            [
                'note' => 'Production core final görseli',
                'visibility' => 'internal',
            ],
            $this->adminUser
        );

        app(OrderItemPrintGraphicWorkflowService::class)->markApproved($graphic->fresh(), $this->adminUser);
        app(OrderItemPrintGraphicWorkflowService::class)->markProductionReady($graphic->fresh(), $this->adminUser);

        $procurement = $production->workForm?->procurement;
        $this->assertNotNull($procurement);

        $procurement->forceFill([
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $production->workForm?->forceFill([
            'procurement_snapshot' => array_merge(
                is_array($production->workForm->procurement_snapshot) ? $production->workForm->procurement_snapshot : [],
                [
                    'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
                    'public_status_label' => 'Ürün üretime hazır',
                ]
            ),
            'updated_by' => $this->adminUser->id,
        ])->save();
    }
}
