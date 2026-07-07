<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QuoteApprovalRequest;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use App\Services\QuoteApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteNotificationIntegrationTest extends TestCase
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

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenant->forceFill([
            'package_key' => 'starter',
            'panel_subdomain' => 'quote-notification-guarded',
            'slug' => 'quote-notification-guarded',
        ])->save();
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
        TenantSetting::setValue($this->tenant->id, 'customer_notification_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'internal_notification_enabled', true, 'boolean');
    }

    public function test_quote_send_creates_customer_and_internal_notification_logs_without_leaking_private_fields(): void
    {
        $quote = $this->createQuote('TK-NOTIF-001');
        $this->enableCustomerQuoteApprovalModule();

        $response = $this->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.show', $quote))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), [
                'contact_name' => 'Ayse Musteri',
                'contact_email' => 'ayse@example.test',
                'contact_phone' => '05320000000',
                'sent_channel' => 'manual',
            ]);

        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));
        $response->assertSessionHas('success');
        $this->assertStringContainsString(
            'Gönderim kaydı oluşturuldu.',
            (string) $response->getSession()->get('success')
        );

        $logs = NotificationLog::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('notification_key', 'quote_sent_to_customer')
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $logs);
        $this->assertTrue($logs->contains(fn (NotificationLog $log) => $log->channel === 'email' && $log->status === NotificationLog::STATUS_PREVIEW));
        $this->assertTrue($logs->contains(fn (NotificationLog $log) => $log->channel === 'whatsapp_link' && $log->status === NotificationLog::STATUS_LINK_CREATED));
        $this->assertTrue($logs->contains(fn (NotificationLog $log) => $log->channel === 'internal' && $log->status === NotificationLog::STATUS_SENT));

        $emailLog = $logs->firstWhere('channel', 'email');
        $whatsappLog = $logs->firstWhere('channel', 'whatsapp_link');
        $internalLog = $logs->firstWhere('channel', 'internal');
        $approvalRequest = $quote->fresh()->latestQuoteApprovalRequest;

        $this->assertSame('quote_sent_to_customer', $emailLog->notification_key);
        $this->assertSame('quote_sent_to_customer', data_get($emailLog->meta_json, 'normalized_event_key'));
        $this->assertStringNotContainsString((string) $approvalRequest?->token, json_encode($emailLog->meta_json, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('group_code', (string) $emailLog->message_preview);
        $this->assertStringNotContainsString('file_path', (string) $emailLog->message_preview);
        $this->assertStringNotContainsString('physical_path', (string) $emailLog->message_preview);
        $this->assertStringNotContainsString('pdh_raw', (string) $emailLog->message_preview);
        $this->assertStringNotContainsString('1440', (string) $emailLog->message_preview);
        $this->assertStringNotContainsString('KDV', (string) $emailLog->message_preview);
        $this->assertStringNotContainsString('Baskı Birim', (string) $emailLog->message_preview);
        $this->assertStringNotContainsString('Baskı Toplam', (string) $emailLog->message_preview);
        $this->assertStringStartsWith('https://wa.me/', (string) data_get($whatsappLog->meta_json, 'url'));
        $this->assertSame('tenant_admin', $internalLog->audience_type);
    }

    public function test_quote_send_skips_missing_email_but_keeps_whatsapp_and_internal_and_feature_guard_still_applies(): void
    {
        $this->customer->forceFill(['email' => null])->save();

        $quote = $this->createQuote('TK-NOTIF-002');
        $this->enableCustomerQuoteApprovalModule();

        $response = $this->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.show', $quote))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), [
                'contact_name' => 'Telefonlu Musteri',
                'contact_email' => '',
                'contact_phone' => '05320000000',
                'sent_channel' => 'manual',
            ]);

        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));

        $logs = NotificationLog::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('notification_key', 'quote_sent_to_customer')
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $logs);
        $this->assertTrue($logs->contains(fn (NotificationLog $log) => $log->channel === 'email' && $log->status === NotificationLog::STATUS_SKIPPED));
        $this->assertTrue($logs->contains(fn (NotificationLog $log) => $log->channel === 'whatsapp_link' && $log->status === NotificationLog::STATUS_LINK_CREATED));
        $this->assertTrue($logs->contains(fn (NotificationLog $log) => $log->channel === 'internal' && $log->status === NotificationLog::STATUS_SENT));

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'customer_quote_approval')
            ->delete();

        $blockedQuote = $this->createQuote('TK-NOTIF-003');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $blockedQuote), [
                'contact_email' => 'blocked@example.test',
            ])
            ->assertForbidden();
    }

    public function test_view_approve_revision_reject_and_convert_emit_notifications_and_failures_do_not_break_flow(): void
    {
        $service = app(QuoteApprovalService::class);
        $this->enableCustomerQuoteApprovalModule();

        $viewQuote = $this->createQuote('TK-NOTIF-004');
        $viewRequest = $service->sendToCustomer($viewQuote, ['contact_email' => 'view@example.test'], $this->adminUser);
        $service->markViewed($viewRequest);
        $this->assertTrue(NotificationLog::query()->where('notification_key', 'quote_customer_viewed')->where('status', NotificationLog::STATUS_SENT)->exists());

        $approvedQuote = $this->createQuote('TK-NOTIF-005');
        $approvedRequest = $service->sendToCustomer($approvedQuote, ['contact_email' => 'approve@example.test'], $this->adminUser);
        $service->approve($approvedRequest, 'Onay verildi');
        $this->assertTrue(NotificationLog::query()->where('notification_key', 'quote_customer_approved')->where('status', NotificationLog::STATUS_SENT)->exists());

        $revisionQuote = $this->createQuote('TK-NOTIF-006');
        $revisionRequest = $service->sendToCustomer($revisionQuote, ['contact_email' => 'revision@example.test'], $this->adminUser);
        $service->requestRevision($revisionRequest, 'Revize gerekli');
        $this->assertTrue(NotificationLog::query()->where('notification_key', 'quote_revision_requested')->where('status', NotificationLog::STATUS_SENT)->exists());

        $rejectedQuote = $this->createQuote('TK-NOTIF-007');
        $rejectedRequest = $service->sendToCustomer($rejectedQuote, ['contact_email' => 'reject@example.test'], $this->adminUser);
        $service->reject($rejectedRequest, 'Uygun değil');
        $this->assertTrue(NotificationLog::query()->where('notification_key', 'quote_rejected')->where('status', NotificationLog::STATUS_SENT)->exists());

        $convertQuote = $this->createQuote('TK-NOTIF-008');
        $convertRequest = $service->sendToCustomer($convertQuote, ['contact_email' => 'convert@example.test'], $this->adminUser);
        $service->approve($convertRequest, 'Tamam');

        $convertResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $convertQuote));

        $convertResponse->assertRedirect();
        $this->assertTrue(NotificationLog::query()->where('notification_key', 'quote_converted_to_order')->where('status', NotificationLog::STATUS_SENT)->exists());

        $failingNotificationService = $this->createMock(NotificationEventService::class);
        $failingNotificationService->method('dispatchEvent')
            ->willThrowException(new \RuntimeException('notification failure'));
        $this->app->instance(NotificationEventService::class, $failingNotificationService);

        $failureSafeService = app(QuoteApprovalService::class);
        $failureQuote = $this->createQuote('TK-NOTIF-009');
        $failureRequest = $failureSafeService->sendToCustomer($failureQuote, ['contact_email' => 'safe@example.test'], $this->adminUser);

        $this->assertInstanceOf(QuoteApprovalRequest::class, $failureRequest);
        $this->assertSame(Order::CUSTOMER_APPROVAL_WAITING, $failureQuote->fresh()->customer_approval_status);

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'notification_center')
            ->delete();

        $coreQuote = $this->createQuote('TK-NOTIF-010');
        $coreRequest = $service->sendToCustomer($coreQuote, ['contact_email' => 'core@example.test'], $this->adminUser);
        $coreLogs = NotificationLog::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('notification_key', 'quote_sent_to_customer')
            ->where('channel', 'internal')
            ->where('related_id', $coreQuote->id)
            ->get();

        $this->assertNotEmpty($coreLogs);
        $this->assertSame(QuoteApprovalRequest::STATUS_WAITING, $coreRequest->status);
    }

    private function enableCustomerQuoteApprovalModule(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'customer_quote_approval',
                'feature_key' => 'customer_quote_approval',
            ],
            ['is_enabled' => true]
        );
    }

    private function createQuote(string $documentNumber): Order
    {
        $quote = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => $documentNumber,
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
            'quote_date' => '2026-06-15',
            'valid_until' => '2026-06-22',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 1200,
            'vat_total' => 240,
            'grand_total' => 1440,
            'product_total' => 1200,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Quote Notification Ürünü',
            'product_code' => 'QNT-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Quote notification kalemi',
            'product_snapshot' => [
                'display_name' => 'Quote Notification Ürünü',
                'group_code' => 'HIDDEN-GROUP',
            ],
            'price_snapshot' => [
                'product_total' => 1200,
                'vat_rate' => 20,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 240, 'scope' => 'product'],
                ],
                'pdh_raw' => ['secret' => 'hidden'],
            ],
            'stock_snapshot' => ['visible_stock_quantity' => 500],
            'list_price' => 12,
            'discount_rate' => 0,
            'unit_price' => 12,
            'line_total' => 1200,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        return $quote->fresh();
    }
}
