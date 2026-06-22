<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormAttachment;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\DeliveryWorkflowService;
use App\Services\Notifications\NotificationEventService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeliveryNotificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        CompanyContact::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'company_id' => $this->customer->id,
                'name' => 'Teslim Musteri',
            ],
            [
                'email' => 'teslim@example.test',
                'phone' => '05320001122',
                'mobile' => '05320001122',
                'is_primary' => true,
            ]
        );

        TenantSetting::setValue($this->tenant->id, 'smtp_is_active', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'whatsapp_is_active', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_email_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_whatsapp_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'internal_notification_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'customer_notification_enabled', true, 'boolean');
    }

    public function test_delivery_notifications_emit_safely_for_workflow_and_attachments(): void
    {
        $deliveryUser = $this->createTenantUserWithRole('delivery.notification@prodelya.local', 'delivery');
        $delivery = $this->createDeliveryRecord('SP-DLV-NOTIF-001');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.deliveries.update-status', $delivery), [
                'action' => 'ready',
                'note' => 'Teslimat hazir C:\\secret\\doc.pdf görünmemeli',
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $readyLogs = NotificationLog::query()
            ->where('notification_key', 'delivery_ready')
            ->where('related_id', $delivery->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $readyLogs);
        $this->assertTrue($readyLogs->contains(fn (NotificationLog $log) => $log->recipient_email === $deliveryUser->email));
        $this->assertLogsAreSafe($readyLogs);

        UserRole::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('user_id', $deliveryUser->id)
            ->delete();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.deliveries.update-status', $delivery->fresh()), [
                'action' => 'partially_delivered',
                'this_delivery_quantity' => '20',
                'delivery_method' => OrderItemWorkFormDelivery::METHOD_CARGO,
                'carrier_name' => 'Yurtiçi Kargo',
                'tracking_number' => 'TRK-NOTIF-001',
                'recipient_name' => 'Ayşe Kaya',
                'package_count' => '1',
                'units_per_package' => '20',
                'note' => 'Kismi teslim, file_path yok.',
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $partialLogs = NotificationLog::query()
            ->where('notification_key', 'delivery_partially_delivered')
            ->where('related_id', $delivery->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $partialLogs);
        $this->assertTrue($partialLogs->contains(fn (NotificationLog $log) => $log->recipient_email === $this->adminUser->email));
        $this->assertLogsAreSafe($partialLogs);

        $partialPayload = $partialLogs->map(fn (NotificationLog $log) => (string) $log->message_preview)->implode("\n");
        $this->assertStringContainsString('20', $partialPayload);
        $this->assertStringContainsString('80', $partialPayload);
        $this->assertStringContainsString('TRK-NOTIF-001', $partialPayload);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.deliveries.update-status', $delivery->fresh()), [
                'action' => 'issue',
                'note' => 'Teslimat adresinde sorun var, group_code yazma.',
            ])
            ->assertRedirect(route('admin.deliveries.show', $delivery));

        $issueLogs = NotificationLog::query()
            ->where('notification_key', 'delivery_problem_reported')
            ->where('related_id', $delivery->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $issueLogs);
        $this->assertLogsAreSafe($issueLogs);

        $completedDelivery = $this->createDeliveryRecord('SP-DLV-NOTIF-002');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.deliveries.update-status', $completedDelivery), [
                'action' => 'delivered',
                'delivery_method' => OrderItemWorkFormDelivery::METHOD_CARGO,
                'carrier_name' => 'Aras Kargo',
                'tracking_number' => 'TRK-NOTIF-002',
                'recipient_name' => 'Mehmet Yılmaz',
                'package_count' => '5',
                'units_per_package' => '20',
                'note' => 'Tam teslim',
            ])
            ->assertRedirect(route('admin.deliveries.show', $completedDelivery));

        $completedLogs = NotificationLog::query()
            ->where('notification_key', 'delivery_completed')
            ->where('related_id', $completedDelivery->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(4, $completedLogs);
        $this->assertTrue($completedLogs->contains(fn (NotificationLog $log) => $log->channel === 'internal' && $log->status === NotificationLog::STATUS_SENT));
        $this->assertTrue($completedLogs->contains(fn (NotificationLog $log) => $log->channel === 'email' && $log->status === NotificationLog::STATUS_PREVIEW && $log->audience_type === 'customer'));
        $this->assertTrue($completedLogs->contains(fn (NotificationLog $log) => $log->channel === 'whatsapp_link' && $log->status === NotificationLog::STATUS_LINK_CREATED));
        $this->assertLogsAreSafe($completedLogs);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $completedDelivery->workForm), [
                'attachment_type' => 'delivery_document',
                'redirect_to' => 'admin.deliveries.show',
                'redirect_delivery_id' => $completedDelivery->id,
                'visibility' => 'customer_visible',
                'note' => 'Müşteriye açık teslim belgesi',
                'file' => UploadedFile::fake()->create('delivery-customer.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect(route('admin.deliveries.show', $completedDelivery));

        $customerVisibleAttachment = OrderItemWorkFormAttachment::query()->latest('id')->firstOrFail();
        $customerVisibleLogs = NotificationLog::query()
            ->where('notification_key', 'delivery_document_uploaded')
            ->where('related_type', $customerVisibleAttachment->getMorphClass())
            ->where('related_id', $customerVisibleAttachment->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(4, $customerVisibleLogs);
        $this->assertTrue($customerVisibleLogs->contains(fn (NotificationLog $log) => $log->audience_type === 'customer' && $log->channel === 'email'));
        $this->assertTrue($customerVisibleLogs->contains(fn (NotificationLog $log) => $log->audience_type === 'customer' && $log->channel === 'whatsapp_link'));
        $this->assertLogsAreSafe($customerVisibleLogs);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.work-forms.attachments.store', $completedDelivery->workForm), [
                'attachment_type' => 'delivery_photo',
                'redirect_to' => 'admin.deliveries.show',
                'redirect_delivery_id' => $completedDelivery->id,
                'visibility' => 'internal',
                'note' => 'İç teslim fotoğrafı',
                'file' => UploadedFile::fake()->image('delivery-internal.jpg'),
            ])
            ->assertRedirect(route('admin.deliveries.show', $completedDelivery));

        $internalAttachment = OrderItemWorkFormAttachment::query()->latest('id')->firstOrFail();
        $internalAttachmentLogs = NotificationLog::query()
            ->where('notification_key', 'delivery_document_uploaded')
            ->where('related_type', $internalAttachment->getMorphClass())
            ->where('related_id', $internalAttachment->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $internalAttachmentLogs);
        $this->assertFalse($internalAttachmentLogs->contains(fn (NotificationLog $log) => $log->audience_type === 'customer'));
        $this->assertLogsAreSafe($internalAttachmentLogs);

        $beforeRenderCount = NotificationLog::query()->count();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.index'))
            ->assertOk();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.deliveries.show', $completedDelivery->fresh()))
            ->assertOk();

        $this->assertSame($beforeRenderCount, NotificationLog::query()->count());

        $failingNotificationService = $this->createMock(NotificationEventService::class);
        $failingNotificationService->method('dispatchEvent')
            ->willThrowException(new \RuntimeException('delivery notification failure'));
        $this->app->instance(NotificationEventService::class, $failingNotificationService);

        $failureDelivery = $this->createDeliveryRecord('SP-DLV-NOTIF-003');
        $failedDelivered = app(DeliveryWorkflowService::class)->markDelivered(
            $failureDelivery->fresh(),
            [
                'delivery_method' => OrderItemWorkFormDelivery::METHOD_CARGO,
                'carrier_name' => 'MNG Kargo',
            ],
            $this->adminUser,
            'Failure safe delivery'
        );

        $this->assertSame(OrderItemWorkFormDelivery::STATUS_DELIVERED, $failedDelivered->delivery_status);

        $publicResponse = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $completedDelivery->fresh('workForm')->workForm->public_tracking_token));

        $publicResponse->assertOk();
        $publicResponse->assertDontSee('group_code', false);
        $publicResponse->assertDontSee('file_path', false);
        $publicResponse->assertDontSee('physical_path', false);
        $publicResponse->assertDontSee('Ödeme bekliyor');
        $publicResponse->assertDontSee('Bakiye var');
    }

    private function assertLogsAreSafe($logs): void
    {
        $serialized = $logs->map(function (NotificationLog $log): string {
            return (string) $log->subject
                . "\n"
                . (string) $log->message_preview
                . "\n"
                . json_encode($log->meta_json, JSON_UNESCAPED_UNICODE);
        })->implode("\n");

        foreach ([
            'unit_price',
            'line_total',
            'print_total',
            'vat_total',
            'grand_total',
            'balance',
            'financial_warning',
            'supplier_cost',
            'subcontractor_cost',
            'group_code',
            'file_path',
            'physical_path',
            'pdh_raw',
            'raw_xml',
            'raw_json',
            'storage/app',
            'C:\\',
            '/var/',
            'KDV',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    private function createTenantUserWithRole(string $email, string $roleKey): User
    {
        $user = User::query()->create([
            'name' => ucfirst(explode('@', $email)[0]),
            'email' => $email,
            'password' => Hash::make('password'),
        ]);

        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'tenant_account_id' => $this->tenant->id,
        ]);

        return $user;
    }

    private function createDeliveryRecord(string $documentNumber): OrderItemWorkFormDelivery
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $documentNumber,
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Delivery Notification Ürünü',
            'product_code' => $documentNumber . '-ITEM',
            'quantity' => 100,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => 'Delivery Notification Ürünü',
                'product_code' => $documentNumber . '-ITEM',
                'warning_badges' => [],
                'group_code' => 'HIDDEN-GROUP',
                'raw_mapping' => ['secret' => 'hidden'],
            ],
            'price_snapshot' => [
                'unit_price' => 55,
                'line_total' => 5500,
                'vat_total' => 1100,
                'grand_total' => 6600,
                'pdh_raw' => ['secret' => 'hidden'],
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 70,
            'discount_rate' => 5,
            'unit_price' => 55,
            'line_total' => 5500,
            'has_print' => true,
            'print_total' => 1500,
            'status' => 'pending',
        ]);

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
            'note' => 'Delivery notification baskı testi',
            'status' => 'draft',
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        $delivery = OrderItemWorkFormDelivery::query()
            ->with(['workForm', 'order', 'order.customer.contacts', 'orderItem'])
            ->latest('id')
            ->firstOrFail();

        $this->prepareDeliveryReady($delivery);

        return $delivery->fresh(['workForm', 'order', 'order.customer.contacts', 'orderItem']);
    }

    private function prepareDeliveryReady(OrderItemWorkFormDelivery $delivery): void
    {
        $workForm = $delivery->workForm->fresh(['procurement', 'printProductions']);

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
