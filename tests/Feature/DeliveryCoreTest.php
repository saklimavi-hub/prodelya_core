<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\DeliveryCreationService;
use App\Services\DeliveryWorkflowService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryCoreTest extends TestCase
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

    public function test_quote_conversion_creates_delivery_record_for_each_work_form(): void
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

        $order->load(['workForms.delivery', 'deliveries']);

        $this->assertCount($order->workForms->count(), $order->deliveries);

        foreach ($order->workForms as $workForm) {
            $this->assertNotNull($workForm->delivery);
            $this->assertSame($workForm->id, $workForm->delivery->work_form_id);
            $this->assertNotEmpty($workForm->delivery_snapshot);
        }
    }

    public function test_delivery_defaults_and_snapshot_are_initialized_safely(): void
    {
        $order = $this->createOrder('SP-DEL-001', 'Kargo');
        $item = $this->createOrderItem($order, [
            'product_name' => 'Teslimat Test Ürünü',
            'product_code' => 'DEL-001',
            'quantity' => 120,
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->first()->fresh(['delivery', 'activityLogs']);
        $delivery = $item->fresh(['delivery.workForm'])->delivery;

        $this->assertNotNull($delivery);
        $this->assertSame($workForm->id, $delivery->work_form_id);
        $this->assertSame(120.0, (float) $delivery->planned_quantity);
        $this->assertSame(0.0, (float) $delivery->delivered_quantity);
        $this->assertSame(120.0, (float) $delivery->remaining_quantity);
        $this->assertSame(OrderItemWorkFormDelivery::STATUS_PENDING, $delivery->delivery_status);
        $this->assertSame(OrderItemWorkFormDelivery::METHOD_CARGO, $delivery->delivery_method);
        $this->assertSame(OrderItemWorkFormDelivery::WARNING_PAYMENT_PENDING, $delivery->financial_warning);
        $this->assertSame(3, $workForm->version);
        $this->assertTrue($workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_record_created'));
        $this->assertSame('Teslimat bekliyor', data_get($workForm->delivery_snapshot, 'public_status_label'));
        $this->assertSame('Ödeme bekliyor', data_get($workForm->delivery_snapshot, 'financial_warning_label'));
        $this->assertSnapshotHasNoForbiddenKeys($delivery->delivery_snapshot);
        $this->assertSnapshotHasNoForbiddenKeys($workForm->delivery_snapshot);
    }

    public function test_delivery_workflow_updates_quantities_statuses_and_work_form_snapshot(): void
    {
        $order = $this->createOrder('SP-DEL-002', 'Kurye');
        $item = $this->createOrderItem($order, [
            'product_name' => 'Kurye Kalemi',
            'product_code' => 'DEL-002',
            'quantity' => 100,
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->first()->fresh();
        $this->markWorkFormDeliveryReady($workForm, $item);
        $delivery = $item->fresh(['delivery.workForm.activityLogs'])->delivery;
        $initialVersion = $workForm->version;

        $workflow = app(DeliveryWorkflowService::class);
        $workflow->markPreparing($delivery->fresh(), $this->adminUser);
        $workflow->markReady($delivery->fresh(), $this->adminUser);
        $workflow->markShipped($delivery->fresh(), $this->adminUser);
        $workflow->markPartiallyDelivered($delivery->fresh(), 40, $this->adminUser);

        $delivery = $delivery->fresh(['workForm.activityLogs']);
        $this->assertSame(OrderItemWorkFormDelivery::STATUS_PARTIALLY_DELIVERED, $delivery->delivery_status);
        $this->assertSame(40.0, (float) $delivery->delivered_quantity);
        $this->assertSame(60.0, (float) $delivery->remaining_quantity);

        $workflow->markDelivered($delivery->fresh(), $this->adminUser);

        $delivery = $delivery->fresh(['workForm.activityLogs']);

        $this->assertSame(OrderItemWorkFormDelivery::STATUS_DELIVERED, $delivery->delivery_status);
        $this->assertSame(100.0, (float) $delivery->delivered_quantity);
        $this->assertSame(0.0, (float) $delivery->remaining_quantity);
        $this->assertTrue($delivery->isDelivered());
        $this->assertSame($initialVersion + 5, $delivery->workForm->version);
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_preparing'));
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_ready'));
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_shipped'));
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_partially_completed'));
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_completed'));
        $this->assertSame('Teslim edildi', data_get($delivery->workForm->delivery_snapshot, 'public_status_label'));
    }

    public function test_duplicate_delivery_is_blocked_and_delivered_quantity_cannot_exceed_planned_quantity(): void
    {
        $order = $this->createOrder('SP-DEL-003', 'Elden');
        $item = $this->createOrderItem($order, ['quantity' => 10]);
        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->first();
        $service = app(DeliveryCreationService::class);

        $first = $service->createForWorkForm($workForm->fresh(), $this->adminUser);
        $second = $service->createForWorkForm($workForm->fresh(), $this->adminUser);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, OrderItemWorkFormDelivery::query()->where('work_form_id', $workForm->id)->count());

        $this->expectException(\InvalidArgumentException::class);
        app(DeliveryWorkflowService::class)->markPartiallyDelivered($first->fresh(), 11, $this->adminUser);
    }

    public function test_delivery_readiness_warnings_and_public_labels_are_generated_safely(): void
    {
        $order = $this->createOrder('SP-DEL-004', 'Ambar');
        $item = $this->createOrderItem($order, [
            'item_type' => 'customer_supplied_product',
            'product_source' => 'customer_supplied',
            'has_print' => true,
            'quantity' => 60,
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->first()->fresh(['delivery']);
        $delivery = $workForm->delivery;

        $warnings = data_get($delivery->delivery_snapshot, 'readiness_warnings', []);

        $this->assertContains('Üretim tamamlanmadan teslimat başlatılmamalı.', $warnings);
        $this->assertContains('Kalite kontrol tamamlanmadı.', $warnings);
        $this->assertContains('Tedarik süreci tamamlanmadı.', $warnings);
        $this->assertSame('Teslimat bekliyor', data_get($delivery->delivery_snapshot, 'public_status_label'));
        $this->assertStringNotContainsString('TL', (string) data_get($delivery->delivery_snapshot, 'financial_warning_label'));
        $this->assertSnapshotHasNoForbiddenKeys($delivery->delivery_snapshot);
    }

    private function createOrder(string $documentNumber, ?string $deliveryType = null): Order
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
            'invoice_status' => 'fatura',
            'delivery_type' => $deliveryType,
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
            'product_name' => 'Delivery Test Ürünü',
            'product_code' => 'DEL-BASE-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'description' => 'Delivery test item',
            'product_snapshot' => [
                'product_name' => 'Delivery Test Ürünü',
                'product_code' => 'DEL-BASE-001',
                'warning_badges' => [],
                'group_code' => 'HIDDEN-GROUP',
                'raw_mapping' => ['secret' => 'value'],
            ],
            'price_snapshot' => [
                'unit_price' => 99.9,
                'line_total' => 999,
                'vat_total' => 199.8,
            ],
            'stock_snapshot' => [
                'local_stock_quantity' => 0,
                'supplier_stock_quantity' => 0,
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

    private function createQuoteViaHttp(): Order
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $this->customer->id,
                'quote_date' => '2026-06-13',
                'valid_until' => '2026-06-20',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Delivery conversion payload',
                'items' => [
                    [
                        'product_name' => 'Delivery Quote Kalem 1',
                        'product_code' => 'DEL-Q-001',
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
                        'product_name' => 'Delivery Quote Kalem 2',
                        'product_code' => 'DEL-Q-002',
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
            ])
            ->assertRedirect();

        return Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();
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
            'delivery_cost',
            'shipment_cost',
            'margin',
            'kdv',
            'physical_path',
            'storage/app',
            'c:\\',
            '/var/',
        ];

        if (!is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            $this->assertNotContains((string) $key, $forbiddenKeys, "Forbidden key [{$key}] leaked into delivery snapshot.");
            $this->assertSnapshotHasNoForbiddenKeys($value);
        }
    }

    private function markWorkFormDeliveryReady(OrderItemWorkForm $workForm, OrderItem $item): void
    {
        $workForm->loadMissing(['printProductions', 'procurement']);

        if ($item->has_print) {
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
        }

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
    }
}
