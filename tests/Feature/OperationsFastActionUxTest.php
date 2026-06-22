<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use App\Services\OrderItemPrintGraphicWorkflowService;
use App\Services\WorkFormAttachmentService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationsFastActionUxTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private User $operatorUser;
    private TenantAccount $tenant;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
        $this->operatorUser = $this->createProductionUser();
    }

    public function test_procurement_fast_actions_are_visible_and_safe(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-UX');
        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-UX-001');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.index', ['supplier_id' => $supplier->id]));

        $response->assertOk();
        $response->assertSee('Talep Aç');
        $response->assertSee('Sipariş Verildi');
        $response->assertSee('Kısmi Geldi');
        $response->assertSee('Geldi');
        $response->assertSee('Toplu Talep Hazırla');
        $response->assertDontSee('Sistem Notu');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_mapping', false);

        $requestRecord = app(\App\Services\SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $supplier->id,
            [$procurement->id],
            $this->adminUser
        );

        $detail = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.show', $procurement->fresh()));

        $detail->assertOk();
        $detail->assertSee('Formu Aç / Yazdır');
        $detail->assertSee($requestRecord->request_number);
        $detail->assertDontSee('group_code', false);
        $detail->assertDontSee('raw_mapping', false);
    }

    public function test_production_fast_actions_and_block_reasons_are_visible_without_cost_leaks(): void
    {
        ['productions' => $productions, 'graphics' => $graphics] = $this->createMultiPrintWorkForm();
        $production = $productions['Tek taraf']->fresh();

        $this->prepareProductionForStart($production, $graphics['1a'], 'operations-fast-action.jpg');

        $index = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index'));

        $index->assertOk();
        $index->assertSee('Üretimi Aç');
        $index->assertSee('Başla');
        $index->assertSee('Kısmi Basıldı');
        $index->assertSee('Tamamı Basıldı');
        $index->assertDontSee('group_code', false);
        $index->assertDontSee('raw_mapping', false);

        $show = $this->actingAs($this->operatorUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $production->fresh()));

        $show->assertOk();
        $show->assertSee('Ne Basılacak?');
        $show->assertSee('Kalan Adet');
        $show->assertSee('Başlamaya Engel');
        $show->assertSee('Fotoğraf Ekle');
        $show->assertDontSee('subcontractor_cost', false);
        $show->assertDontSee('group_code', false);
        $show->assertDontSee('raw_mapping', false);
    }

    public function test_delivery_fast_actions_and_visibility_labels_are_clear_and_safe(): void
    {
        $delivery = $this->createDeliveryRecord([
            'product_name' => 'UX Teslimat Ürünü',
            'product_code' => 'UX-DLV-001',
        ]);

        $index = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.index'));

        $index->assertOk();
        $index->assertSee('Teslimatı Aç');
        $index->assertSee('Kısmi Teslim');
        $index->assertSee('Tamamı Teslim');
        $index->assertSee('Belge / Fotoğraf');
        $index->assertDontSee('price_snapshot', false);
        $index->assertDontSee('group_code', false);
        $index->assertDontSee('raw_mapping', false);

        $show = $this->actingAs($this->operatorUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.show', $delivery));

        $show->assertOk();
        $show->assertSee('Belge / Fotoğraf Ekle');
        $show->assertSee('Müşteri Görür');
        $show->assertSee('Sadece İç Ekip');
        $show->assertDontSee('Finans · Yetkili');
        $show->assertDontSee('price_snapshot', false);
        $show->assertDontSee('group_code', false);
        $show->assertDontSee('raw_mapping', false);
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

    private function createProcurement(Supplier $supplier, SupplierSource $source, string $orderNumber): OrderItemProcurement
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
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'supplier_feed',
            'product_name' => $supplier->code . ' Ürün',
            'product_code' => $supplier->code . '-001',
            'supplier_source_id' => $source->id,
            'quantity' => 100,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => $supplier->code . ' Ürün',
                'product_code' => $supplier->code . '-001',
                'supplier_name' => $supplier->name,
                'group_code' => 'HIDDEN-GROUP',
                'raw_mapping' => ['secret' => 'hidden'],
            ],
            'price_snapshot' => [
                'unit_price' => 20,
                'line_total' => 2000,
                'vat_total' => 400,
            ],
            'stock_snapshot' => [
                'local_stock_quantity' => 0,
                'supplier_stock_quantity' => 40,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 24,
            'discount_rate' => 5,
            'unit_price' => 20,
            'line_total' => 2000,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->first();

        $systemFolder = $workForm?->systemWorkFolder;

        if ($systemFolder?->relative_path) {
            Storage::disk('local')->makeDirectory($systemFolder->relative_path . '/03_URETIM_TESLIMAT');
        }

        return $item->fresh(['procurement.workForm', 'procurement.order', 'procurement.supplierRequestItems.request'])->procurement;
    }

    private function createMultiPrintWorkForm(): array
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-OP-' . random_int(1000, 9999),
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'customer_supplied',
            'product_name' => 'Operator Panel Product',
            'product_code' => 'OP-001',
            'quantity' => 80,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => 'Operator Panel Product',
                'product_code' => 'OP-001',
                'group_code' => 'HIDDEN',
                'raw_mapping' => ['secret' => 'hidden'],
            ],
            'has_print' => true,
            'print_total' => 80,
            'status' => 'pending',
        ]);

        OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_location' => 'Gövde',
            'print_size' => 'Standart',
            'print_quantity' => 80,
            'status' => 'draft',
        ]);

        OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'Lazer',
            'print_option' => 'Gövde',
            'print_location' => 'Kapak',
            'print_size' => '40 x 15 mm',
            'print_quantity' => 80,
            'status' => 'draft',
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->firstOrFail();

        $productions = OrderItemPrintProduction::query()
            ->where('work_form_id', $workForm->id)
            ->with(['workForm.procurement', 'orderItemPrint.graphicOperation'])
            ->get()
            ->keyBy(fn (OrderItemPrintProduction $production) => $production->orderItemPrint->print_option);

        $graphics = OrderItemPrintGraphic::query()
            ->where('order_item_work_form_id', $workForm->id)
            ->orderBy('sequence_code')
            ->get()
            ->keyBy('sequence_code');

        return [
            'productions' => $productions,
            'graphics' => $graphics,
        ];
    }

    private function prepareProductionForStart(
        OrderItemPrintProduction $production,
        OrderItemPrintGraphic $graphic,
        string $fileName
    ): void {
        app(WorkFormAttachmentService::class)->attachGraphicVisualToPrintGraphic(
            $graphic,
            UploadedFile::fake()->image($fileName),
            [
                'note' => 'Operator panel test graphic',
                'visibility' => 'internal',
            ],
            $this->adminUser
        );

        app(OrderItemPrintGraphicWorkflowService::class)->markApproved($graphic->fresh(), $this->adminUser);
        app(OrderItemPrintGraphicWorkflowService::class)->markProductionReady($graphic->fresh(), $this->adminUser);

        $procurement = $production->workForm->procurement;
        $procurement?->forceFill([
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'received_quantity' => (float) $production->planned_quantity,
            'remaining_quantity' => 0,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $production->workForm->forceFill([
            'procurement_snapshot' => array_merge(
                is_array($production->workForm->procurement_snapshot) ? $production->workForm->procurement_snapshot : [],
                [
                    'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
                    'procurement_status_label' => 'Tamamı Geldi',
                    'public_status_label' => 'Ürün hazır',
                    'received_quantity' => (float) $production->planned_quantity,
                    'remaining_quantity' => 0,
                ]
            ),
            'updated_by' => $this->adminUser->id,
        ])->save();
    }

    private function createDeliveryRecord(array $itemOverrides = []): OrderItemWorkFormDelivery
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-DLV-' . random_int(1000, 9999),
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $item = OrderItem::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'UI Delivery Product',
            'product_code' => 'UI-DEL-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => 'UI Delivery Product',
                'product_code' => 'UI-DEL-001',
                'warning_badges' => [],
                'group_code' => 'HIDDEN-GROUP',
                'raw_mapping' => ['secret' => 'hidden'],
            ],
            'price_snapshot' => [
                'unit_price' => 55,
                'line_total' => 1100,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 70,
            'discount_rate' => 5,
            'unit_price' => 55,
            'line_total' => 1100,
            'has_print' => true,
            'print_total' => 150,
            'status' => 'pending',
        ], $itemOverrides));

        OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_location' => 'Gövde',
            'print_color' => 'Tek Renk',
            'print_size' => 'Standart',
            'print_quantity' => 100,
            'note' => 'Teslimat UI baskı testi',
            'status' => 'draft',
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        $delivery = OrderItemWorkFormDelivery::query()
            ->with(['workForm', 'order', 'order.customer', 'orderItem'])
            ->latest('id')
            ->firstOrFail();

        $workForm = $delivery->workForm->fresh(['procurement', 'printProductions']);
        $systemFolder = $delivery->workForm->systemWorkFolder;

        if ($systemFolder?->relative_path) {
            Storage::disk('local')->makeDirectory($systemFolder->relative_path . '/03_URETIM_TESLIMAT');
        }

        foreach ($workForm->printProductions as $production) {
            $production->forceFill([
                'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
                'completed_quantity' => (float) $production->planned_quantity,
                'remaining_quantity' => 0,
            ])->save();
        }

        $workForm->forceFill([
            'production_snapshot' => array_merge(
                is_array($workForm->production_snapshot) ? $workForm->production_snapshot : [],
                [
                    'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
                    'production_status_label' => 'Tamamlandı',
                    'completed_quantity' => (float) $item->quantity,
                    'remaining_quantity' => 0,
                    'public_status_label' => 'Üretim tamamlandı',
                ]
            ),
        ])->save();

        if ($workForm->procurement) {
            $workForm->procurement->forceFill([
                'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
                'received_quantity' => (float) $item->quantity,
                'remaining_quantity' => 0,
            ])->save();

            $workForm->forceFill([
                'procurement_snapshot' => array_merge(
                    is_array($workForm->procurement_snapshot) ? $workForm->procurement_snapshot : [],
                    [
                        'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
                        'procurement_status_label' => 'Tamamı Geldi',
                        'received_quantity' => (float) $item->quantity,
                        'remaining_quantity' => 0,
                    ]
                ),
            ])->save();
        }

        return $delivery->fresh(['workForm', 'order', 'order.customer', 'orderItem']);
    }

    private function createProductionUser(): User
    {
        $user = User::query()->create([
            'name' => 'Operations User',
            'email' => 'operations-user-' . uniqid() . '@prodelya.local',
            'password' => 'password',
        ]);

        $roleId = \App\Models\Role::query()->where('key', 'production')->value('id');

        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'tenant_account_id' => $this->tenant->id,
        ]);

        return $user;
    }
}
