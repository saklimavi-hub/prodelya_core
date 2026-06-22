<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\DeliveryCreationService;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\NotificationPolicyService;
use App\Services\Notifications\NotificationTemplateService;
use App\Services\Notifications\NotificationVariableBuilder;
use App\Services\Notifications\TenantNotificationSettingsService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationCoreTest extends TestCase
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

    public function test_notification_tables_and_models_are_available(): void
    {
        $this->assertTrue(Schema::hasTable('notification_templates'));
        $this->assertTrue(Schema::hasTable('notification_logs'));

        $template = NotificationTemplate::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'quote_sent_email',
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'audience_type' => NotificationTemplate::AUDIENCE_CUSTOMER,
            'title' => 'Teklif Hazır',
            'subject' => '{{quote_number}} hazır',
            'body' => 'Merhaba {{customer_name}}',
            'variables_json' => ['customer_name', 'quote_number'],
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $log = NotificationLog::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'quote_sent_email',
            'channel' => NotificationLog::CHANNEL_EMAIL,
            'recipient_type' => 'customer',
            'recipient_name' => 'ABC İnşaat A.Ş.',
            'recipient_email' => 'musteri@firma.com',
            'subject' => 'Test',
            'message_preview' => 'Kısa içerik',
            'status' => NotificationLog::STATUS_PENDING,
            'created_by' => $this->adminUser->id,
        ]);

        $this->assertSame($this->tenant->id, $template->tenant?->id);
        $this->assertTrue($template->isEmail());
        $this->assertTrue($template->isCustomerAudience());
        $this->assertSame('E-posta', $template->safeChannelLabel());
        $this->assertSame('Müşteri', $template->safeAudienceLabel());

        $this->assertSame($this->tenant->id, $log->tenant?->id);
        $this->assertSame('Bekliyor', $log->safeStatusLabel());
        $this->assertSame('E-posta', $log->safeChannelLabel());
    }

    public function test_tenant_notification_settings_service_masks_and_preserves_password_correctly(): void
    {
        $service = app(TenantNotificationSettingsService::class);

        $service->updateSmtpSettings($this->tenant, [
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_username' => 'notify@example.test',
            'smtp_password' => 'super-secret-pass',
            'smtp_encryption' => 'tls',
            'smtp_from_name' => 'Prodelya Bildirim',
            'smtp_from_email' => 'notify@example.test',
            'smtp_reply_to_email' => 'reply@example.test',
            'smtp_is_active' => true,
            'smtp_test_email' => 'test@example.test',
        ], $this->adminUser);

        $raw = TenantSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('key', 'smtp_password')
            ->firstOrFail();

        $this->assertNotSame('super-secret-pass', $raw->value);
        $this->assertSame('super-secret-pass', $service->getSmtpConfig($this->tenant)['password']);

        $masked = $service->maskSmtpSettingsForDisplay($this->tenant);
        $this->assertArrayNotHasKey('smtp_password', $masked);
        $this->assertTrue($masked['smtp_password_configured']);

        $service->updateSmtpSettings($this->tenant, [
            'smtp_host' => 'smtp.changed.test',
            'smtp_password' => '',
        ], $this->adminUser);

        $smtpConfig = $service->getSmtpConfig($this->tenant);
        $this->assertSame('smtp.changed.test', $smtpConfig['host']);
        $this->assertSame('super-secret-pass', $smtpConfig['password']);
    }

    public function test_customer_variable_sets_and_template_rendering_block_forbidden_financial_variables(): void
    {
        ['order' => $order, 'workForm' => $workForm] = $this->createOrderWithWorkForm('SP-NOT-001');

        $builder = app(NotificationVariableBuilder::class);
        $templateService = app(NotificationTemplateService::class);

        $customerVariables = $builder->buildForWorkForm($workForm, NotificationTemplate::AUDIENCE_CUSTOMER);
        $this->assertArrayHasKey('public_tracking_url', $customerVariables);
        $this->assertStringContainsString($workForm->public_tracking_token, $customerVariables['public_tracking_url']);
        $this->assertArrayNotHasKey('grand_total', $customerVariables);
        $this->assertArrayNotHasKey('balance_due', $customerVariables);
        $this->assertArrayNotHasKey('price_snapshot', $customerVariables);
        $this->assertArrayNotHasKey('group_code', $customerVariables);
        $this->assertArrayNotHasKey('file_path', $customerVariables);
        $this->assertArrayNotHasKey('physical_path', $customerVariables);

        $template = NotificationTemplate::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'delivery_shipped',
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'audience_type' => NotificationTemplate::AUDIENCE_CUSTOMER,
            'subject' => '{{order_number}} hazır',
            'body' => 'Toplam: {{grand_total}} / Takip: {{public_tracking_url}}',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $rendered = $templateService->render($template, $customerVariables, NotificationTemplate::AUDIENCE_CUSTOMER);

        $this->assertContains('grand_total', $rendered['blocked_variables']);
        $this->assertStringNotContainsString('grand_total', $rendered['body']);
        $this->assertStringContainsString($workForm->public_tracking_token, $rendered['body']);

        $payment = OrderPayment::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'customer_company_id' => $this->customer->id,
            'payment_type' => OrderPayment::TYPE_COLLECTION,
            'amount' => 1000,
            'currency' => 'TL',
            'payment_method' => OrderPayment::METHOD_BANK_TRANSFER,
            'paid_at' => now(),
            'created_by' => $this->adminUser->id,
        ]);

        $financeVariables = $builder->buildForPayment($payment, NotificationTemplate::AUDIENCE_FINANCE);
        $this->assertSame(1000.0, $financeVariables['payment_amount']);
        $this->assertSame('TL', $financeVariables['payment_currency']);
        $this->assertArrayNotHasKey('file_path', $financeVariables);
        $this->assertArrayNotHasKey('physical_path', $financeVariables);
    }

    public function test_notification_policy_respects_channel_toggles_internal_only_events_and_sms_rules(): void
    {
        $settingsService = app(TenantNotificationSettingsService::class);
        $policy = app(NotificationPolicyService::class);

        $settingsService->updateSmtpSettings($this->tenant, [
            'smtp_is_active' => false,
        ], $this->adminUser);
        $settingsService->updateChannelSettings($this->tenant, [
            'whatsapp_is_active' => false,
            'notification_email_enabled' => false,
            'notification_whatsapp_enabled' => false,
            'notification_sms_enabled' => false,
            'customer_notification_enabled' => true,
            'internal_notification_enabled' => true,
        ], $this->adminUser);

        $this->assertTrue($policy->isInternalOnly('quote_created'));
        $this->assertTrue($policy->isCustomerFacing('delivery_shipped'));
        $this->assertFalse($policy->canNotify('quote_created', NotificationTemplate::CHANNEL_EMAIL, NotificationTemplate::AUDIENCE_CUSTOMER, $this->tenant));
        $this->assertFalse($policy->canNotify('payment_created', NotificationTemplate::CHANNEL_EMAIL, NotificationTemplate::AUDIENCE_CUSTOMER, $this->tenant));
        $this->assertFalse($policy->canNotify('delivery_shipped', NotificationTemplate::CHANNEL_SMS, NotificationTemplate::AUDIENCE_CUSTOMER, $this->tenant));
        $this->assertFalse($policy->canNotify('delivery_shipped', NotificationTemplate::CHANNEL_EMAIL, NotificationTemplate::AUDIENCE_CUSTOMER, $this->tenant));
        $this->assertFalse($policy->canNotify('delivery_shipped', NotificationTemplate::CHANNEL_WHATSAPP_LINK, NotificationTemplate::AUDIENCE_CUSTOMER, $this->tenant));

        $settingsService->updateSmtpSettings($this->tenant, [
            'smtp_is_active' => true,
        ], $this->adminUser);
        $settingsService->updateChannelSettings($this->tenant, [
            'whatsapp_is_active' => true,
            'notification_email_enabled' => true,
            'notification_whatsapp_enabled' => true,
        ], $this->adminUser);

        $this->assertTrue($policy->canNotify('delivery_shipped', NotificationTemplate::CHANNEL_EMAIL, NotificationTemplate::AUDIENCE_CUSTOMER, $this->tenant));
        $this->assertTrue($policy->canNotify('delivery_shipped', NotificationTemplate::CHANNEL_WHATSAPP_LINK, NotificationTemplate::AUDIENCE_CUSTOMER, $this->tenant));
        $this->assertTrue($policy->canNotify('production_completed', NotificationTemplate::CHANNEL_INTERNAL, NotificationTemplate::AUDIENCE_INTERNAL, $this->tenant));
    }

    public function test_dispatch_service_sanitizes_preview_supports_whatsapp_link_created_and_tenant_scopes(): void
    {
        $dispatch = app(NotificationDispatchService::class);

        $secondTenant = TenantAccount::query()->create([
            'name' => 'İkinci Tenant',
            'legal_name' => 'İkinci Tenant Ltd.',
            'slug' => 'ikinci-tenant',
            'panel_subdomain' => 'ikinci-tenant',
            'status' => 'active',
        ]);

        $failed = $dispatch->logFailed([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'delivery_shipped',
            'channel' => NotificationLog::CHANNEL_EMAIL,
            'recipient_type' => 'customer',
            'recipient_email' => 'musteri@firma.com',
            'subject' => 'Test',
            'message_preview' => str_repeat('x', 520) . ' file_path C:\\secret',
            'error_message' => 'SMTP bağlantı hatası',
            'dispatch_mode' => 'sync',
            'created_by' => $this->adminUser->id,
        ]);

        $this->assertTrue($failed->isFailed());
        $this->assertLessThanOrEqual(500, mb_strlen((string) $failed->message_preview));
        $this->assertStringNotContainsString('file_path', (string) $failed->message_preview);
        $this->assertStringNotContainsString('C:\\secret', (string) $failed->message_preview);

        $whatsApp = $dispatch->createWhatsappLink($this->tenant, '0532 000 00 00', 'Merhaba {{customer_name}}', [
            'notification_key' => 'quote_sent_whatsapp',
            'recipient_type' => 'customer',
            'recipient_name' => 'ABC İnşaat A.Ş.',
            'related_type' => Order::class,
            'related_id' => 99,
        ], $this->adminUser);

        $this->assertStringStartsWith('https://wa.me/905320000000?text=', $whatsApp['url']);
        $this->assertSame('905320000000', $whatsApp['phone']);
        $this->assertTrue($whatsApp['log']->isWhatsappLinkCreated());
        $this->assertSame(NotificationLog::STATUS_LINK_CREATED, $whatsApp['log']->status);

        NotificationLog::query()->create([
            'tenant_account_id' => $secondTenant->id,
            'channel' => NotificationLog::CHANNEL_INTERNAL,
            'status' => NotificationLog::STATUS_SENT,
            'dispatch_mode' => 'manual',
        ]);

        $this->assertCount(2, NotificationLog::query()->forTenant($this->tenant->id)->get());
        $this->assertCount(1, NotificationLog::query()->forTenant($secondTenant->id)->get());
    }

    public function test_template_lookup_prefers_tenant_specific_template_over_global_default(): void
    {
        $service = app(NotificationTemplateService::class);

        NotificationTemplate::query()->create([
            'tenant_account_id' => null,
            'notification_key' => 'delivery_shipped',
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'audience_type' => NotificationTemplate::AUDIENCE_CUSTOMER,
            'subject' => 'Global',
            'body' => 'Global body',
        ]);

        $tenantTemplate = NotificationTemplate::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'notification_key' => 'delivery_shipped',
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'audience_type' => NotificationTemplate::AUDIENCE_CUSTOMER,
            'subject' => 'Tenant',
            'body' => 'Tenant body',
        ]);

        $resolved = $service->findTemplate($this->tenant, 'delivery_shipped', NotificationTemplate::CHANNEL_EMAIL, NotificationTemplate::AUDIENCE_CUSTOMER);

        $this->assertNotNull($resolved);
        $this->assertSame($tenantTemplate->id, $resolved->id);
    }

    private function createOrderWithWorkForm(string $documentNumber): array
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
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
            'vat_breakdown_json' => [
                ['rate' => 10, 'total' => 1800, 'scope' => 'product'],
                ['rate' => 20, 'total' => 2700, 'scope' => 'print'],
            ],
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Notification Test Ürünü',
            'product_code' => 'NOT-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Notification test kalemi',
            'product_snapshot' => [
                'product_name' => 'Notification Test Ürünü',
                'product_code' => 'NOT-001',
                'group_code' => 'HIDDEN-GROUP',
                'raw_mapping' => ['secret' => 'hidden'],
            ],
            'price_snapshot' => [
                'product_total' => 18000,
                'print_total' => 4500,
                'subtotal' => 22500,
                'vat_total' => 4500,
                'grand_total' => 27000,
            ],
            'stock_snapshot' => [
                'supplier_stock_quantity' => 0,
                'local_stock_quantity' => 0,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 200,
            'discount_rate' => 5,
            'unit_price' => 181.23,
            'line_total' => 18000,
            'has_print' => false,
            'print_total' => 4500,
            'status' => 'pending',
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->first()->fresh(['delivery']);
        if (!$workForm->delivery) {
            app(DeliveryCreationService::class)->createForWorkForm($workForm, $this->adminUser);
        }

        $workForm = $workForm->fresh(['delivery']);

        return [
            'order' => $order,
            'workForm' => $workForm,
        ];
    }
}
