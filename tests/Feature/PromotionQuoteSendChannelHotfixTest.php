<?php

namespace Tests\Feature;

use App\Mail\QuoteCustomerApprovalMail;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PromotionQuoteSendChannelHotfixTest extends TestCase
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
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        CompanyContact::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'company_id' => $this->customer->id,
                'name' => 'Ayşe Müşteri',
            ],
            [
                'email' => 'primary@example.test',
                'phone' => '05320000000',
                'mobile' => '05320000000',
                'is_primary' => true,
            ]
        );

        $this->enableQuoteApprovalFeatures();
        $this->enableWhatsappFeatures();
    }

    public function test_PromotionQuoteStandardSendRequiresCustomerEmailTest(): void
    {
        CompanyContact::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('company_id', $this->customer->id)
            ->delete();

        $this->customer->forceFill([
            'email' => null,
            'phone' => '05325550000',
            'mobile' => '05325550000',
        ])->save();

        $quote = $this->createQuote('TK-CH-001');

        $response = $this->postSend($quote, [
            'contact_name' => 'E-postasız Müşteri',
            'contact_email' => '',
            'contact_phone' => '05325550000',
            'sent_channel' => 'manual',
        ]);

        $response->assertSessionHasErrors([
            'error' => 'Müşteri e-posta adresi olmadığı için teklif maili gönderilemedi.',
        ]);
    }

    public function test_PromotionQuoteStandardSendSendsMailTest(): void
    {
        $this->seedSmtpSettings();
        $this->expectQuoteMailSend(1);

        $quote = $this->createQuote('TK-CH-002');

        $response = $this->postSend($quote, [
            'contact_name' => 'Ayşe Müşteri',
            'contact_email' => 'ayse@example.test',
            'contact_phone' => '05320000000',
            'sent_channel' => 'manual',
        ]);

        $response->assertSessionHas('success');
        $this->assertStringContainsString('Teklif müşteriye e-posta olarak gönderildi.', (string) session('success'));

        $emailLog = $this->latestLog($quote, NotificationLog::CHANNEL_EMAIL, 'quote_sent_to_customer');
        $this->assertSame(NotificationLog::STATUS_SENT, $emailLog->status);
    }

    public function test_PromotionQuoteEmailPreviewDoesNotSendMailTest(): void
    {
        $this->seedSmtpSettings();
        Mail::shouldReceive('mailer')->never();

        $quote = $this->createQuote('TK-CH-003');

        $response = $this->postSend($quote, [
            'contact_name' => 'Ayşe Müşteri',
            'contact_email' => 'ayse@example.test',
            'contact_phone' => '05320000000',
            'sent_channel' => 'email',
        ]);

        $response->assertSessionHas('success', 'E-posta önizlemesi oluşturuldu. Bu işlem müşteriye mail göndermez.');

        $emailLog = $this->latestLog($quote, NotificationLog::CHANNEL_EMAIL, 'quote_sent_to_customer');
        $this->assertSame(NotificationLog::STATUS_PREVIEW, $emailLog->status);
    }

    public function test_PromotionQuoteWhatsappLinkDoesNotRequireEmailTest(): void
    {
        $quote = $this->createQuote('TK-CH-004');

        $response = $this->postSend($quote, [
            'contact_name' => 'Telefonlu Müşteri',
            'contact_email' => '',
            'contact_phone' => '05322723484',
            'sent_channel' => 'whatsapp_link',
        ]);

        $response->assertSessionHas('success', 'WhatsApp mesaj linki oluşturuldu. Public onay linki hazır.');
        $this->assertNull($this->findLatestLog($quote, NotificationLog::CHANNEL_EMAIL, 'quote_sent_to_customer'));
        $this->assertNotNull($this->latestLog($quote, NotificationLog::CHANNEL_WHATSAPP_LINK, 'whatsapp_manual_link'));
    }

    public function test_PromotionQuoteWhatsappLinkRequiresPhoneTest(): void
    {
        CompanyContact::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('company_id', $this->customer->id)
            ->delete();

        $this->customer->forceFill([
            'phone' => null,
            'mobile' => null,
        ])->save();

        $quote = $this->createQuote('TK-CH-005');

        $response = $this->postSend($quote, [
            'contact_name' => 'Telefonsuz Müşteri',
            'contact_email' => '',
            'contact_phone' => '',
            'sent_channel' => 'whatsapp_link',
        ]);

        $response->assertSessionHasErrors([
            'error' => 'WhatsApp linki oluşturulamadı. Müşteri WhatsApp/telefon numarası bulunamadı.',
        ]);
    }

    public function test_PromotionQuoteWhatsappLinkBuildsWaMeUrlTest(): void
    {
        $quote = $this->createQuote('TK-CH-006');

        $this->postSend($quote, [
            'contact_name' => 'Ayşe Müşteri',
            'contact_email' => '',
            'contact_phone' => '+90 532 272 34 84',
            'sent_channel' => 'whatsapp_link',
        ]);

        $log = $this->latestLog($quote, NotificationLog::CHANNEL_WHATSAPP_LINK, 'whatsapp_manual_link');
        $result = session('whatsapp_result');

        $this->assertIsArray($result);
        $this->assertStringStartsWith('https://wa.me/905322723484?text=', (string) ($result['url'] ?? ''));
        $this->assertSame('[public-onay-linki-gizlendi]', (string) data_get($log->meta_json, 'url'));
    }

    public function test_PromotionQuoteWhatsappPhoneNormalizationTest(): void
    {
        $normalizer = app(PhoneNumberNormalizer::class);

        $this->assertSame('902125018233', $normalizer->toWhatsappDialString('02125018233'));
        $this->assertSame('902125018233', $normalizer->toWhatsappDialString('2125018233'));
        $this->assertSame('902125018233', $normalizer->toWhatsappDialString('+90 212 501 82 33'));
        $this->assertSame('905322723484', $normalizer->toWhatsappDialString('05322723484'));
        $this->assertSame('905322723484', $normalizer->toWhatsappDialString('5322723484'));
    }

    public function test_PromotionQuoteWhatsappFixedLineNumberAcceptedTest(): void
    {
        $quote = $this->createQuote('TK-CH-007');

        $response = $this->postSend($quote, [
            'contact_name' => 'Sabit Hatlı Müşteri',
            'contact_email' => '',
            'contact_phone' => '02125018233',
            'sent_channel' => 'whatsapp_link',
        ]);

        $response->assertSessionHas('success');

        $log = $this->latestLog($quote, NotificationLog::CHANNEL_WHATSAPP_LINK, 'whatsapp_manual_link');
        $this->assertSame('902125018233', $log->recipient_phone);
    }

    public function test_PromotionQuoteWhatsappMessageUrlClickableFormatTest(): void
    {
        $quote = $this->createQuote('TK-CH-008');

        $this->postSend($quote, [
            'contact_name' => 'Ayşe Müşteri',
            'contact_email' => '',
            'contact_phone' => '05322723484',
            'sent_channel' => 'whatsapp_link',
        ]);

        $log = $this->latestLog($quote, NotificationLog::CHANNEL_WHATSAPP_LINK, 'whatsapp_manual_link');
        $publicUrl = route('public.quotes.approval.show', ['token' => $quote->fresh()->latestQuoteApprovalRequest->token]);
        $result = session('whatsapp_result');
        $waUrl = (string) (($result['url'] ?? ''));
        parse_str((string) parse_url($waUrl, PHP_URL_QUERY), $query);

        $this->assertStringContainsString("\n" . $publicUrl, urldecode((string) ($query['text'] ?? '')));
        $this->assertSame('[public-onay-linki-gizlendi]', (string) data_get($log->meta_json, 'url'));
    }

    public function test_PromotionQuoteSendChannelLogsAreCorrectTest(): void
    {
        $quote = $this->createQuote('TK-CH-009');

        $this->postSend($quote, [
            'contact_name' => 'Ayşe Müşteri',
            'contact_email' => '',
            'contact_phone' => '05320000000',
            'sent_channel' => 'email',
        ]);

        $logs = NotificationLog::query()
            ->where('related_id', $quote->id)
            ->orderBy('id')
            ->get();

        $this->assertTrue($logs->contains(fn (NotificationLog $log) => $log->channel === NotificationLog::CHANNEL_EMAIL && $log->status === NotificationLog::STATUS_PREVIEW));
        $this->assertTrue($logs->contains(fn (NotificationLog $log) => $log->channel === NotificationLog::CHANNEL_WHATSAPP_LINK && $log->status === NotificationLog::STATUS_LINK_CREATED));
        $this->assertTrue($logs->contains(fn (NotificationLog $log) => $log->channel === NotificationLog::CHANNEL_INTERNAL && $log->status === NotificationLog::STATUS_SENT));
    }

    public function test_PromotionQuoteSendChannelNoSensitiveLeakTest(): void
    {
        $quote = $this->createQuote('TK-CH-010');

        $this->postSend($quote, [
            'contact_name' => 'Ayşe Müşteri',
            'contact_email' => '',
            'contact_phone' => '05320000000',
            'sent_channel' => 'whatsapp_link',
        ]);

        $log = $this->latestLog($quote, NotificationLog::CHANNEL_WHATSAPP_LINK, 'whatsapp_manual_link');

        $this->assertStringNotContainsString('group_code', (string) $log->message_preview);
        $this->assertStringNotContainsString('supplier_cost', (string) $log->message_preview);
        $this->assertStringNotContainsString('subcontractor_cost', (string) $log->message_preview);
        $this->assertStringNotContainsString('pdh_raw', (string) $log->message_preview);
    }

    private function postSend(Order $quote, array $payload)
    {
        return $this->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.show', $quote))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), $payload)
            ->assertRedirect(route('admin.promotion-quotes.show', $quote));
    }

    private function latestLog(Order $quote, string $channel, string $notificationKey): NotificationLog
    {
        return $this->findLatestLog($quote, $channel, $notificationKey) ?? throw new \RuntimeException('Expected notification log not found.');
    }

    private function findLatestLog(Order $quote, string $channel, string $notificationKey): ?NotificationLog
    {
        return NotificationLog::query()
            ->where('related_id', $quote->id)
            ->where('channel', $channel)
            ->where('notification_key', $notificationKey)
            ->latest('id')
            ->first();
    }

    private function expectQuoteMailSend(int $count): void
    {
        Mail::shouldReceive('forgetMailers')->times($count * 2);
        Mail::shouldReceive('mailer')->times($count)->with('tenant_smtp_runtime')->andReturnSelf();
        Mail::shouldReceive('to')->times($count)->with('ayse@example.test')->andReturnSelf();
        Mail::shouldReceive('send')->times($count)->with(\Mockery::type(QuoteCustomerApprovalMail::class))->andReturnNull();
    }

    private function enableQuoteApprovalFeatures(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => 'public_quote_approval',
            ],
            ['is_enabled' => true]
        );
    }

    private function enableWhatsappFeatures(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => 'whatsapp_links',
            ],
            ['is_enabled' => true]
        );

        TenantSetting::setValue($this->tenant->id, 'whatsapp_is_active', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_whatsapp_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'customer_notification_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'internal_notification_enabled', true, 'boolean');
    }

    private function seedSmtpSettings(): void
    {
        TenantSetting::setValue($this->tenant->id, 'smtp_is_active', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'smtp_host', 'smtp.example.test', 'string');
        TenantSetting::setValue($this->tenant->id, 'smtp_port', 587, 'integer');
        TenantSetting::setValue($this->tenant->id, 'smtp_username', 'notify@example.test', 'string');
        TenantSetting::setValue($this->tenant->id, 'smtp_password', 'enc::already-encrypted-placeholder', 'string');
        TenantSetting::setValue($this->tenant->id, 'smtp_encryption', 'tls', 'string');
        TenantSetting::setValue($this->tenant->id, 'smtp_from_name', 'Prodelya Bildirim', 'string');
        TenantSetting::setValue($this->tenant->id, 'smtp_from_email', 'notify@example.test', 'string');
        TenantSetting::setValue($this->tenant->id, 'smtp_reply_to_email', 'reply@example.test', 'string');
        TenantSetting::setValue($this->tenant->id, 'notification_email_enabled', true, 'boolean');
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
            'quote_date' => '2026-07-01',
            'valid_until' => '2026-07-08',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 1200,
            'vat_total' => 240,
            'grand_total' => 1440,
            'product_total' => 1200,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
            'notes' => 'Müşteriye güvenli teklif notu',
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Hotfix Test Ürünü',
            'product_code' => 'HTX-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Hotfix test kalemi',
            'product_snapshot' => [
                'display_name' => 'Hotfix Test Ürünü',
                'group_code' => 'SECRET-GROUP',
                'pdh_raw' => ['hidden' => true],
            ],
            'price_snapshot' => [
                'product_total' => 1200,
                'vat_rate' => 20,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 240, 'scope' => 'product'],
                ],
                'supplier_cost' => 250,
                'subcontractor_cost' => 150,
                'profit' => 300,
                'file_path' => '/hidden/file.pdf',
                'physical_path' => 'C:\\hidden\\file.pdf',
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
