<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\OrderPaymentService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeliveryUiTest extends TestCase
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
        Storage::fake('local');

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_delivery_index_renders_real_records_and_sidebar_route_without_financial_or_technical_leaks(): void
    {
        $delivery = $this->createDeliveryRecord([
            'product_name' => 'UI Teslimat Kalemi',
            'product_code' => 'UI-DLV-001',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.index'));

        $response->assertOk();
        $response->assertSee('Teslimat / Paket / Koli Takibi');
        $response->assertSee(route('admin.deliveries.index'), false);
        $response->assertSee($delivery->order->document_number);
        $response->assertSee($this->customer->legal_name);
        $response->assertSee('UI Teslimat Kalemi');
        $response->assertSee('UI-DLV-001');
        $response->assertSee('Teslimat truth kaynağı work form / order item delivery kaydıdır.');
        $response->assertDontSee('unit_price', false);
        $response->assertDontSee('KDV', false);
        $response->assertDontSee('grand_total', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_mapping', false);
        $response->assertDontSee('price_snapshot', false);
    }

    public function test_delivery_show_renders_snapshot_forms_links_and_upload_forms(): void
    {
        $delivery = $this->createDeliveryRecord([
            'product_name' => 'Detay Teslimat Ürünü',
            'product_code' => 'UI-DET-DLV',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.show', $delivery));

        $response->assertOk();
        $response->assertSee('Teslimat Detayı');
        $response->assertSee('Detay Teslimat Ürünü');
        $response->assertSee('UI-DET-DLV');
        $response->assertSee('Kısmi Teslim');
        $response->assertSee('Tam Teslim');
        $response->assertSee('Fotoğraf Ekle');
        $response->assertSee('Belge Ekle');
        $response->assertSee('capture="environment"', false);
        $response->assertSee(route('admin.work-forms.show', $delivery->workForm), false);
        $response->assertSee(route('admin.orders.show', $delivery->order), false);
        $response->assertDontSee('price_snapshot', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_mapping', false);
    }

    public function test_tenant_external_delivery_access_returns_403(): void
    {
        $otherTenant = TenantAccount::query()->create([
            'name' => 'Second Tenant',
            'legal_name' => 'Second Tenant Ltd.',
            'slug' => 'second-tenant-delivery',
            'panel_subdomain' => 'second-tenant-delivery',
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-OTHER-DLV',
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_name' => 'Other Tenant Delivery',
            'product_code' => 'OTH-DLV-001',
            'quantity' => 10,
            'unit' => 'Adet',
        ]);

        $workForm = OrderItemWorkForm::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'work_form_number' => 'IF-OTHER-DLV',
            'item_sequence' => 1,
            'status' => 'active',
            'version' => 1,
            'public_tracking_token' => 'other-delivery-token',
        ]);

        $delivery = OrderItemWorkFormDelivery::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'work_form_id' => $workForm->id,
            'planned_quantity' => 10,
            'remaining_quantity' => 10,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_PENDING,
            'financial_warning' => OrderItemWorkFormDelivery::WARNING_NONE,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.show', $delivery))
            ->assertForbidden();
    }

    public function test_delivery_details_form_updates_snapshot_version_and_logs(): void
    {
        $delivery = $this->createDeliveryRecord([
            'product_name' => 'Detay Güncelleme Ürünü',
            'product_code' => 'UI-UPD-DLV',
        ]);

        $initialVersion = $delivery->workForm->version;

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.deliveries.update-details', $delivery), [
                'delivery_method' => OrderItemWorkFormDelivery::METHOD_CARGO,
                'carrier_name' => 'Yurtiçi Kargo',
                'tracking_number' => 'TRK-123456',
                'recipient_name' => 'Ahmet Yılmaz',
                'recipient_phone' => '05550001122',
                'delivery_note' => 'Teslim öncesi aranacak.',
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $delivery = $delivery->fresh(['workForm.activityLogs']);

        $this->assertSame(OrderItemWorkFormDelivery::METHOD_CARGO, $delivery->delivery_method);
        $this->assertSame('Yurtiçi Kargo', $delivery->carrier_name);
        $this->assertSame('TRK-123456', $delivery->tracking_number);
        $this->assertSame('Ahmet Yılmaz', $delivery->recipient_name);
        $this->assertSame('05550001122', $delivery->recipient_phone);
        $this->assertSame('Teslim öncesi aranacak.', $delivery->delivery_note);
        $this->assertSame(OrderItemWorkFormDelivery::WARNING_PAYMENT_PENDING, $delivery->financial_warning);
        $this->assertGreaterThan($initialVersion, $delivery->workForm->version);
        $this->assertSame('Ödeme bekliyor', data_get($delivery->workForm->delivery_snapshot, 'financial_warning_label'));
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_details_updated'));
    }

    public function test_delivery_status_actions_update_quantities_snapshot_and_logs(): void
    {
        $delivery = $this->createDeliveryRecord([
            'product_name' => 'Aksiyon Teslimat Ürünü',
            'product_code' => 'UI-ACT-DLV',
        ]);

        $initialVersion = $delivery->workForm->version;

        foreach ([
            ['action' => 'preparing'],
            ['action' => 'ready'],
            ['action' => 'shipped'],
            ['action' => 'courier_out'],
            ['action' => 'partially_delivered', 'delivered_quantity' => '20'],
            ['action' => 'delivered'],
        ] as $payload) {
            $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->patch(route('admin.deliveries.update-status', $delivery), $payload)
                ->assertRedirect(route('admin.deliveries.show', $delivery));
        }

        $delivery = $delivery->fresh(['workForm.activityLogs']);

        $this->assertSame(OrderItemWorkFormDelivery::STATUS_DELIVERED, $delivery->delivery_status);
        $this->assertSame((float) $delivery->planned_quantity, (float) $delivery->delivered_quantity);
        $this->assertSame(0.0, (float) $delivery->remaining_quantity);
        $this->assertGreaterThan($initialVersion, $delivery->workForm->version);
        $this->assertSame('Teslim edildi', data_get($delivery->workForm->delivery_snapshot, 'public_status_label'));
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_preparing'));
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_ready'));
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_shipped'));
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'courier_out_for_delivery'));
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_partially_completed'));
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_completed'));
    }

    public function test_delivery_status_partial_action_accepts_new_this_delivery_quantity_field(): void
    {
        $delivery = $this->createDeliveryRecord();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.deliveries.update-status', $delivery), [
                'action' => 'partially_delivered',
                'this_delivery_quantity' => '20',
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $delivery = $delivery->fresh();
        $this->assertSame(20.0, (float) $delivery->delivered_quantity);
        $this->assertSame(80.0, (float) $delivery->remaining_quantity);
    }

    public function test_delivered_quantity_overflow_is_blocked(): void
    {
        $delivery = $this->createDeliveryRecord([
            'product_name' => 'Validation Delivery',
            'product_code' => 'UI-VAL-DLV',
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.deliveries.show', $delivery))
            ->patch(route('admin.deliveries.update-status', $delivery), [
                'action' => 'partially_delivered',
                'delivered_quantity' => '1000',
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery))
            ->assertSessionHasErrors('delivered_quantity');

        $delivery = $delivery->fresh();
        $this->assertSame(0.0, (float) $delivery->delivered_quantity);
        $this->assertSame((float) $delivery->planned_quantity, (float) $delivery->remaining_quantity);
    }

    public function test_delivery_photo_and_document_upload_work_via_existing_work_form_attachment_infrastructure(): void
    {
        $delivery = $this->createDeliveryRecord([
            'product_name' => 'Upload Delivery',
            'product_code' => 'UI-UPL-DLV',
        ]);

        $show = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.show', $delivery));

        $show->assertOk();
        $show->assertSee('Teslimat Fotoğrafı');
        $show->assertSee('Teslimat Belgesi');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $delivery->workForm), [
                'attachment_type' => 'delivery_photo',
                'redirect_to' => 'admin.deliveries.show',
                'redirect_delivery_id' => $delivery->id,
                'visibility' => 'internal',
                'note' => 'UI teslimat fotoğrafı',
                'file' => UploadedFile::fake()->image('delivery-ui.jpg'),
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $delivery->workForm), [
                'attachment_type' => 'delivery_document',
                'redirect_to' => 'admin.deliveries.show',
                'redirect_delivery_id' => $delivery->id,
                'visibility' => 'customer_visible',
                'note' => 'UI teslimat belgesi',
                'file' => UploadedFile::fake()->create('delivery-ui.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $delivery = $delivery->fresh(['workForm.attachments', 'workForm.activityLogs']);
        $this->assertSame(2, $delivery->workForm->deliveryAttachments()->count());
        $this->assertSame(1, (int) data_get($delivery->workForm->delivery_snapshot, 'photo_count'));
        $this->assertSame(1, (int) data_get($delivery->workForm->delivery_snapshot, 'document_count'));
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_photo_added'));
        $this->assertTrue($delivery->workForm->activityLogs->contains(fn ($log) => $log->action_type === 'delivery_document_added'));
    }

    public function test_public_tracking_does_not_show_financial_warning(): void
    {
        $delivery = $this->createDeliveryRecord([
            'product_name' => 'Public Delivery',
            'product_code' => 'UI-PUB-DLV',
        ]);

        app(OrderPaymentService::class)->createPayment($delivery->order, [
            'payment_type' => 'tahsilat',
            'amount' => 1,
            'currency' => 'TL',
            'due_date' => now()->subDays(3),
            'payment_note' => 'Public warning trigger',
        ], $this->adminUser);

        $response = $this->get(route('public.work-forms.track', $delivery->workForm->public_tracking_token));

        $response->assertOk();
        $response->assertDontSee('Tahsilat onayı bekleniyor');
        $response->assertDontSee('Ödeme bekliyor');
        $response->assertDontSee('Bakiye var');
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
            'stock_snapshot' => [
                'supplier_stock_quantity' => 33,
                'local_stock_quantity' => 0,
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

        return $delivery->fresh(['workForm', 'order', 'order.customer', 'orderItem']);
    }
}
