<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeliveryPackageKoliV1Test extends TestCase
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

        Storage::fake('public');

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_delivery_table_contains_v1_package_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('order_item_work_form_deliveries', [
            'package_count',
            'units_per_package',
            'packaged_quantity',
            'package_type',
            'package_note',
            'delivery_document_no',
            'tracking_number',
            'carrier_name',
            'recipient_name',
        ]));
    }

    public function test_index_renders_grouped_order_product_and_package_information(): void
    {
        [$firstDelivery, $secondDelivery] = $this->createGroupedDeliveries();

        $firstDelivery->forceFill([
            'package_count' => 5,
            'units_per_package' => 20,
            'package_type' => OrderItemWorkFormDelivery::PACKAGE_BOX,
        ])->save();

        $secondDelivery->forceFill([
            'package_count' => 2,
            'units_per_package' => 25,
            'package_type' => OrderItemWorkFormDelivery::PACKAGE_CASE,
        ])->save();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.index'));

        $response->assertOk();
        $response->assertSee('Teslimat / Paket / Koli Takibi');
        $response->assertSee($firstDelivery->order->document_number);
        $response->assertSee('Takım: 2 ürün / teslimat satırı');
        $response->assertSee('Takım Kalem');
        $response->assertSee('Takım Ajanda');
        $response->assertSee('5 koli · koli içi 20');
        $response->assertSee('2 kutu · koli içi 25');
    }

    public function test_partial_delivery_updates_package_fields_quantities_status_and_snapshot(): void
    {
        $delivery = $this->createPrintedDeliveryReady();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.deliveries.update-status', $delivery), [
                'action' => 'partially_delivered',
                'this_delivery_quantity' => '20',
                'package_count' => '1',
                'units_per_package' => '20',
                'package_type' => OrderItemWorkFormDelivery::PACKAGE_BOX,
                'recipient_name' => 'Ayşe Kaya',
                'delivery_document_no' => 'IRS-2001',
                'tracking_number' => 'TRK-2001',
                'carrier_name' => 'Yurtiçi Kargo',
                'note' => 'İlk koli çıktı.',
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $delivery = $delivery->fresh();

        $this->assertSame(20.0, (float) $delivery->delivered_quantity);
        $this->assertSame(80.0, (float) $delivery->remaining_quantity);
        $this->assertSame(OrderItemWorkFormDelivery::STATUS_PARTIALLY_DELIVERED, $delivery->delivery_status);
        $this->assertNotNull($delivery->partially_delivered_at);
        $this->assertSame(1, $delivery->package_count);
        $this->assertSame(20, $delivery->units_per_package);
        $this->assertSame(20, $delivery->packaged_quantity);
        $this->assertSame(OrderItemWorkFormDelivery::PACKAGE_BOX, $delivery->package_type);
        $this->assertSame('Ayşe Kaya', $delivery->recipient_name);
        $this->assertSame('IRS-2001', $delivery->delivery_document_no);
        $this->assertSame('TRK-2001', $delivery->tracking_number);
        $this->assertSame('Yurtiçi Kargo', $delivery->carrier_name);
        $this->assertSame(1, data_get($delivery->delivery_snapshot, 'package_count'));
        $this->assertSame('Koli', data_get($delivery->delivery_snapshot, 'package_type_label'));
        $this->assertSame('Ayşe Kaya', data_get($delivery->workForm->fresh()->delivery_snapshot, 'recipient_name'));
    }

    public function test_partial_delivery_cannot_exceed_remaining_quantity(): void
    {
        $delivery = $this->createPrintedDeliveryReady();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.deliveries.show', $delivery))
            ->patch(route('admin.deliveries.update-status', $delivery), [
                'action' => 'partially_delivered',
                'this_delivery_quantity' => '200',
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery))
            ->assertSessionHasErrors('this_delivery_quantity');

        $this->assertSame(0.0, (float) $delivery->fresh()->delivered_quantity);
    }

    public function test_full_delivery_completes_remaining_quantity_and_sets_delivered_at(): void
    {
        $delivery = $this->createPrintedDeliveryReady();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.deliveries.update-status', $delivery), [
                'action' => 'delivered',
                'recipient_name' => 'Mehmet Şahin',
                'package_count' => '5',
                'units_per_package' => '20',
                'package_type' => OrderItemWorkFormDelivery::PACKAGE_BOX,
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $delivery = $delivery->fresh();

        $this->assertSame(100.0, (float) $delivery->delivered_quantity);
        $this->assertSame(0.0, (float) $delivery->remaining_quantity);
        $this->assertSame(OrderItemWorkFormDelivery::STATUS_DELIVERED, $delivery->delivery_status);
        $this->assertNotNull($delivery->delivered_at);
    }

    public function test_printed_product_cannot_deliver_more_than_completed_production_quantity(): void
    {
        $delivery = $this->createPrintedDeliveryReady();
        $production = $delivery->workForm->printProductions->firstOrFail();

        $production->forceFill([
            'completed_quantity' => 35,
            'remaining_quantity' => 65,
            'production_status' => OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
        ])->save();

        $delivery->workForm->forceFill([
            'production_snapshot' => array_merge(
                is_array($delivery->workForm->production_snapshot) ? $delivery->workForm->production_snapshot : [],
                [
                    'completed_quantity' => 35,
                    'remaining_quantity' => 65,
                    'production_status' => OrderItemPrintProduction::STATUS_PARTIALLY_COMPLETED,
                ]
            ),
        ])->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.deliveries.show', $delivery))
            ->patch(route('admin.deliveries.update-status', $delivery), [
                'action' => 'partially_delivered',
                'this_delivery_quantity' => '40',
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery))
            ->assertSessionHasErrors('this_delivery_quantity');
    }

    public function test_non_printed_product_can_deliver_when_procurement_ready_without_production_requirement(): void
    {
        $delivery = $this->createNonPrintedDelivery(OrderItemProcurement::STATUS_FULLY_RECEIVED, 80);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.deliveries.update-status', $delivery), [
                'action' => 'partially_delivered',
                'this_delivery_quantity' => '30',
                'package_type' => OrderItemWorkFormDelivery::PACKAGE_BAG,
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $delivery = $delivery->fresh();
        $this->assertSame(30.0, (float) $delivery->delivered_quantity);
        $this->assertSame(70.0, (float) $delivery->remaining_quantity);
    }

    public function test_delivery_update_does_not_affect_other_product_in_same_order(): void
    {
        [$firstDelivery, $secondDelivery] = $this->createGroupedDeliveries();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.deliveries.update-status', $firstDelivery), [
                'action' => 'partially_delivered',
                'this_delivery_quantity' => '15',
            ])
            ->assertRedirect(route('admin.deliveries.show', $firstDelivery));

        $this->assertSame(15.0, (float) $firstDelivery->fresh()->delivered_quantity);
        $this->assertSame(0.0, (float) $secondDelivery->fresh()->delivered_quantity);
    }

    public function test_public_tracking_shows_only_customer_visible_delivery_attachments_and_no_financial_warning(): void
    {
        $delivery = $this->createPrintedDeliveryReady();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $delivery->workForm), [
                'attachment_type' => 'delivery_photo',
                'redirect_to' => 'admin.deliveries.show',
                'redirect_delivery_id' => $delivery->id,
                'visibility' => 'customer_visible',
                'file' => UploadedFile::fake()->image('customer-visible.jpg'),
            ])
            ->assertRedirect();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $delivery->workForm), [
                'attachment_type' => 'delivery_document',
                'redirect_to' => 'admin.deliveries.show',
                'redirect_delivery_id' => $delivery->id,
                'visibility' => 'internal',
                'file' => UploadedFile::fake()->create('internal-only.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $response = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $delivery->workForm->public_tracking_token));

        $response->assertOk();
        $response->assertSee('customer-visible.jpg');
        $response->assertDontSee('internal-only.pdf');
        $response->assertDontSee('Ödeme bekliyor');
        $response->assertDontSee('Bakiye var');
    }

    private function createPrintedDeliveryReady(): OrderItemWorkFormDelivery
    {
        $delivery = $this->createSingleDelivery(hasPrint: true);
        $this->preparePrintedReady($delivery);

        return $delivery->fresh(['workForm.printProductions', 'order', 'order.customer', 'orderItem']);
    }

    private function createNonPrintedDelivery(string $procurementStatus, float $receivedQuantity): OrderItemWorkFormDelivery
    {
        $delivery = $this->createSingleDelivery(hasPrint: false);
        $workForm = $delivery->workForm->fresh(['procurement']);

        $workForm->procurement?->forceFill([
            'procurement_status' => $procurementStatus,
            'received_quantity' => $receivedQuantity,
            'remaining_quantity' => max($delivery->planned_quantity - $receivedQuantity, 0),
        ])->save();

        $workForm->forceFill([
            'procurement_snapshot' => array_merge(
                is_array($workForm->procurement_snapshot) ? $workForm->procurement_snapshot : [],
                [
                    'procurement_status' => $procurementStatus,
                    'procurement_status_label' => OrderItemProcurement::statusLabels()[$procurementStatus] ?? $procurementStatus,
                    'received_quantity' => $receivedQuantity,
                    'remaining_quantity' => max($delivery->planned_quantity - $receivedQuantity, 0),
                ]
            ),
        ])->save();

        return $delivery->fresh(['workForm.procurement', 'order', 'order.customer', 'orderItem']);
    }

    private function createGroupedDeliveries(): array
    {
        $order = $this->createOrder();

        $firstItem = $this->createItem($order, 'Takım Kalem', 'TEAM-001', true);
        $secondItem = $this->createItem($order, 'Takım Ajanda', 'TEAM-002', true);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        $deliveries = OrderItemWorkFormDelivery::query()
            ->where('order_id', $order->id)
            ->with(['workForm.printProductions', 'order', 'order.customer', 'orderItem'])
            ->orderBy('id')
            ->get()
            ->values();

        $this->preparePrintedReady($deliveries[0]);
        $this->preparePrintedReady($deliveries[1]);

        return [
            $deliveries[0]->fresh(['workForm.printProductions', 'order', 'order.customer', 'orderItem']),
            $deliveries[1]->fresh(['workForm.printProductions', 'order', 'order.customer', 'orderItem']),
        ];
    }

    private function createSingleDelivery(bool $hasPrint): OrderItemWorkFormDelivery
    {
        $order = $this->createOrder();
        $this->createItem($order, $hasPrint ? 'Baskılı Teslim Ürünü' : 'Baskısız Teslim Ürünü', $hasPrint ? 'DLV-PRINT-001' : 'DLV-NP-001', $hasPrint);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        return OrderItemWorkFormDelivery::query()
            ->with(['workForm.printProductions', 'workForm.procurement', 'order', 'order.customer', 'orderItem'])
            ->where('order_id', $order->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function createOrder(): Order
    {
        return Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-DLV-V1-' . random_int(1000, 9999),
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);
    }

    private function createItem(Order $order, string $name, string $code, bool $hasPrint): OrderItem
    {
        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => $name,
            'product_code' => $code,
            'quantity' => 100,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => $name,
                'product_code' => $code,
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
            'has_print' => $hasPrint,
            'print_total' => $hasPrint ? 150 : 0,
            'status' => 'pending',
        ]);

        if ($hasPrint) {
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
                'note' => 'Delivery V1 test baskısı',
                'status' => 'draft',
            ]);
        }

        return $item;
    }

    private function preparePrintedReady(OrderItemWorkFormDelivery $delivery): void
    {
        $workForm = $delivery->workForm->fresh(['printProductions', 'procurement']);

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
                    'completed_quantity' => 100,
                    'remaining_quantity' => 0,
                    'public_status_label' => 'Üretim tamamlandı',
                ]
            ),
        ])->save();

        $workForm->procurement?->forceFill([
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'received_quantity' => 100,
            'remaining_quantity' => 0,
        ])->save();

        $workForm->forceFill([
            'procurement_snapshot' => array_merge(
                is_array($workForm->procurement_snapshot) ? $workForm->procurement_snapshot : [],
                [
                    'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
                    'procurement_status_label' => 'Tamamı Geldi',
                    'received_quantity' => 100,
                    'remaining_quantity' => 0,
                ]
            ),
        ])->save();
    }
}
