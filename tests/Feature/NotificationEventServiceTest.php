<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\DeliveryCreationService;
use App\Services\Notifications\NotificationEventService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationEventServiceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private TenantAccount $tenant;
    private User $adminUser;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

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
                'name' => 'Ayse Musteri',
            ],
            [
                'email' => 'ayse@example.test',
                'phone' => '05320000000',
                'mobile' => '05320000000',
                'is_primary' => true,
            ]
        );

        TenantSetting::setValue($this->tenant->id, 'smtp_is_active', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'whatsapp_is_active', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_email_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_whatsapp_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_sms_enabled', false, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'customer_notification_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'internal_notification_enabled', true, 'boolean');
    }

    public function test_notification_event_service_dispatches_with_alias_template_fallback_policy_and_guarded_context(): void
    {
        ['order' => $order, 'workForm' => $workForm] = $this->createOrderWithWorkForm('SP-EVT-001');
        $service = app(NotificationEventService::class);

        NotificationTemplate::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'quote_sent_to_customer',
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'audience_type' => NotificationTemplate::AUDIENCE_CUSTOMER,
            'subject' => 'Tenant override {{quote_number}}',
            'body' => 'Merhaba {{customer_name}} {{grand_total}} {{file_path}} {{group_code}} {{public_quote_url}}',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $quoteDispatch = $service->dispatchEvent($this->tenant, 'quote_sent_email', $order, [
            'created_by' => $this->adminUser,
            'context' => [
                'public_quote_url' => 'https://example.test/teklif/abc123',
                'file_path' => 'C:\\secret\\quote.pdf',
                'group_code' => 'HIDDEN-GROUP',
                'pdh_raw' => '<xml>secret</xml>',
                'token' => 'raw-token',
            ],
        ]);

        $this->assertSame('quote_sent_email', $quoteDispatch['event_key']);
        $this->assertSame('quote_sent_to_customer', $quoteDispatch['normalized_event_key']);
        $this->assertSame(1, $quoteDispatch['recipients_count']);
        $this->assertContains(NotificationTemplate::CHANNEL_EMAIL, $quoteDispatch['channels']);
        $this->assertContains(NotificationTemplate::CHANNEL_WHATSAPP_LINK, $quoteDispatch['channels']);
        $this->assertContains(NotificationTemplate::CHANNEL_INTERNAL, $quoteDispatch['channels']);

        $quoteLogs = collect($quoteDispatch['logs']);
        $this->assertCount(3, $quoteLogs);
        $this->assertTrue($quoteLogs->every(fn (NotificationLog $log) => $log->notification_key === 'quote_sent_to_customer'));
        $this->assertTrue($quoteLogs->contains(fn (NotificationLog $log) => $log->channel === NotificationTemplate::CHANNEL_EMAIL && $log->status === NotificationLog::STATUS_PREVIEW));
        $this->assertTrue($quoteLogs->contains(fn (NotificationLog $log) => $log->channel === NotificationTemplate::CHANNEL_WHATSAPP_LINK && $log->status === NotificationLog::STATUS_LINK_CREATED));
        $this->assertTrue($quoteLogs->contains(fn (NotificationLog $log) => $log->channel === NotificationTemplate::CHANNEL_INTERNAL && $log->status === NotificationLog::STATUS_SENT));

        $emailLog = $quoteLogs->first(fn (NotificationLog $log) => $log->channel === NotificationTemplate::CHANNEL_EMAIL);
        $whatsAppLog = $quoteLogs->first(fn (NotificationLog $log) => $log->channel === NotificationTemplate::CHANNEL_WHATSAPP_LINK);

        $this->assertSame('quote_sent_email', data_get($emailLog->meta_json, 'original_event_key'));
        $this->assertSame('quote_sent_to_customer', data_get($emailLog->meta_json, 'normalized_event_key'));
        $this->assertSame('customer', data_get($emailLog->meta_json, 'audience_type'));
        $this->assertStringContainsString('Tenant override', (string) $emailLog->subject);
        $this->assertStringContainsString('Ayse Musteri', (string) $emailLog->message_preview);
        $this->assertStringNotContainsString('C:\\secret', (string) $emailLog->message_preview);
        $this->assertStringNotContainsString('HIDDEN-GROUP', (string) $emailLog->message_preview);
        $this->assertStringNotContainsString('grand_total', (string) $emailLog->message_preview);
        $this->assertNull(data_get($emailLog->meta_json, 'context.file_path'));
        $this->assertNull(data_get($emailLog->meta_json, 'context.group_code'));
        $this->assertNull(data_get($emailLog->meta_json, 'context.pdh_raw'));
        $this->assertNull(data_get($emailLog->meta_json, 'context.token'));
        $this->assertNull(data_get($emailLog->meta_json, 'context.public_quote_url'));
        $this->assertStringStartsWith('https://wa.me/', (string) data_get($whatsAppLog->meta_json, 'url'));

        $adminDispatch = $service->dispatchEvent($this->tenant, 'quote_customer_approved', $order, [
            'created_by' => $this->adminUser,
            'recipient_override' => [
                'type' => 'user',
                'name' => 'Tenant Admin',
                'email' => 'admin@prodelya.local',
                'user_id' => $this->adminUser->id,
            ],
        ]);

        $this->assertSame('admin', $adminDispatch['logs'][0]->audience_type);
        $this->assertSame(NotificationLog::STATUS_SENT, $adminDispatch['logs'][0]->status);

        TenantSetting::setValue($this->tenant->id, 'notification_whatsapp_enabled', false, 'boolean');
        $whatsappSkipped = $service->dispatchEvent($this->tenant, 'quote_sent_whatsapp', $order, [
            'channels' => [NotificationTemplate::CHANNEL_WHATSAPP_LINK],
            'created_by' => $this->adminUser,
        ]);

        $this->assertCount(1, $whatsappSkipped['logs']);
        $this->assertSame(NotificationLog::STATUS_SKIPPED, $whatsappSkipped['logs'][0]->status);

        $missingEmail = $service->dispatchEvent($this->tenant, 'quote_sent_to_customer', $order, [
            'channels' => [NotificationTemplate::CHANNEL_EMAIL],
            'recipient_override' => [[
                'type' => 'customer',
                'name' => 'Telefonlu Alıcı',
                'phone' => '05320000000',
            ]],
            'created_by' => $this->adminUser,
        ]);

        $this->assertSame(NotificationLog::STATUS_SKIPPED, $missingEmail['logs'][0]->status);

        $missingPhone = $service->dispatchEvent($this->tenant, 'quote_sent_to_customer', $order, [
            'channels' => [NotificationTemplate::CHANNEL_WHATSAPP_LINK],
            'recipient_override' => [[
                'type' => 'customer',
                'name' => 'Sadece Email',
                'email' => 'only@example.test',
            ]],
            'created_by' => $this->adminUser,
        ]);

        $this->assertSame(NotificationLog::STATUS_SKIPPED, $missingPhone['logs'][0]->status);

        $smsDispatch = $service->dispatchEvent($this->tenant, 'quote_sent_to_customer', $order, [
            'channels' => [NotificationTemplate::CHANNEL_SMS],
            'created_by' => $this->adminUser,
        ]);

        $this->assertSame(NotificationLog::STATUS_SKIPPED, $smsDispatch['logs'][0]->status);

        NotificationTemplate::query()->where('notification_key', 'payment_received')->delete();
        NotificationTemplate::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'payment_received',
            'channel' => NotificationTemplate::CHANNEL_INTERNAL,
            'audience_type' => NotificationTemplate::AUDIENCE_FINANCE,
            'subject' => 'Odeme alindi {{order_number}}',
            'body' => 'Odeme: {{payment_amount}} {{payment_currency}} / Ref: {{payment_reference}} / Dosya: {{file_path}}',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $payment = OrderPayment::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'customer_company_id' => $this->customer->id,
            'payment_type' => OrderPayment::TYPE_COLLECTION,
            'amount' => 1500,
            'currency' => 'TL',
            'payment_method' => OrderPayment::METHOD_BANK_TRANSFER,
            'paid_at' => now(),
            'payment_reference' => 'TRX-EVT-001',
            'created_by' => $this->adminUser->id,
        ]);

        $financeDispatch = $service->dispatchEvent($this->tenant, 'payment_completed', $payment, [
            'created_by' => $this->adminUser,
            'channels' => [NotificationTemplate::CHANNEL_INTERNAL],
            'context' => [
                'file_path' => '/var/secret.pdf',
                'group_code' => 'FIN-GROUP',
            ],
        ]);

        $financeLog = $financeDispatch['logs'][0];
        $this->assertSame('payment_received', $financeDispatch['normalized_event_key']);
        $this->assertSame(NotificationLog::STATUS_SENT, $financeLog->status);
        $this->assertStringContainsString('1500', (string) $financeLog->message_preview);
        $this->assertStringContainsString('TRX-EVT-001', (string) $financeLog->message_preview);
        $this->assertStringNotContainsString('/var/secret.pdf', (string) $financeLog->message_preview);
        $this->assertNull(data_get($financeLog->meta_json, 'context.file_path'));
        $this->assertNull(data_get($financeLog->meta_json, 'context.group_code'));

        TenantSetting::setValue($this->tenant->id, 'notification_whatsapp_enabled', true, 'boolean');
        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'notification_center')
            ->delete();

        $coreDispatch = $service->dispatchEvent($this->tenant, 'quote_created', $order, [
            'channels' => [NotificationTemplate::CHANNEL_INTERNAL],
            'created_by' => $this->adminUser,
        ]);

        $this->assertSame(NotificationLog::STATUS_SENT, $coreDispatch['logs'][0]->status);
    }

    private function createOrderWithWorkForm(string $documentNumber): array
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => $documentNumber,
            'source_quote_number' => 'TK-' . substr($documentNumber, 3),
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'product_total' => 18000,
            'print_total' => 4500,
            'subtotal' => 22500,
            'vat_total' => 4500,
            'grand_total' => 27000,
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Event Test Ürünü',
            'product_code' => 'EVT-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Event test kalemi',
            'product_snapshot' => [
                'product_name' => 'Event Test Ürünü',
                'group_code' => 'HIDDEN-GROUP',
            ],
            'price_snapshot' => [
                'grand_total' => 27000,
            ],
            'catalog_source' => 'tenant_catalog',
            'unit_price' => 181.23,
            'line_total' => 18000,
            'has_print' => false,
            'status' => 'pending',
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->first()->fresh(['delivery']);

        if (!$workForm->delivery) {
            app(DeliveryCreationService::class)->createForWorkForm($workForm, $this->adminUser);
        }

        return [
            'order' => $order->fresh(['customer.contacts', 'items', 'workForms']),
            'workForm' => $workForm->fresh(['delivery']),
        ];
    }
}
