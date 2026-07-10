<?php

namespace Tests\Feature;

use App\Mail\QuoteCustomerApprovalMail;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PromotionQuoteCustomerMailHotfixTest extends TestCase
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

    public function test_promotion_quote_send_to_customer_sends_real_mail(): void
    {
        $this->seedSmtpSettings();
        $this->expectQuoteMailSend(1, function (QuoteCustomerApprovalMail $mail): void {
            $this->assertSame('Ayşe Müşteri', $mail->customerName);
            $this->assertNotSame('', $mail->publicApprovalUrl);
        });

        $quote = $this->createQuote('TK-HOTFIX-001');

        $response = $this->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.show', $quote))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), [
                'contact_name' => 'Ayşe Müşteri',
                'contact_email' => 'ayse@example.test',
                'contact_phone' => '05320000000',
            ]);

        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));
        $response->assertSessionHas('success');
        $this->assertStringContainsString('Teklif müşteriye e-posta olarak gönderildi.', (string) $response->getSession()->get('success'));

        $emailLog = NotificationLog::query()
            ->where('notification_key', 'quote_sent_to_customer')
            ->where('channel', NotificationLog::CHANNEL_EMAIL)
            ->where('related_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(NotificationLog::STATUS_SENT, $emailLog->status);
        $this->assertSame('ayse@example.test', $emailLog->recipient_email);
    }

    public function test_promotion_quote_resend_to_customer_sends_real_mail(): void
    {
        $this->seedSmtpSettings();
        $this->expectQuoteMailSend(2);

        $quote = $this->createQuote('TK-HOTFIX-002');

        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->adminUser)
                ->from(route('admin.promotion-quotes.show', $quote))
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->post(route('admin.promotion-quotes.send-to-customer', $quote), [
                    'contact_name' => 'Ayşe Müşteri',
                    'contact_email' => 'ayse@example.test',
                    'contact_phone' => '05320000000',
                ])
                ->assertRedirect(route('admin.promotion-quotes.show', $quote));
        }

        $quote->refresh();
        $this->assertSame(2, $quote->quoteApprovalRequests()->count());
        $this->assertSame(2, $quote->quoteSendSnapshots()->count());
        $this->assertSame(QuoteApprovalRequest::STATUS_CANCELLED, $quote->quoteApprovalRequests()->oldest('id')->firstOrFail()->status);
        $this->assertSame(QuoteApprovalRequest::STATUS_WAITING, $quote->quoteApprovalRequests()->latest('id')->firstOrFail()->status);
    }

    public function test_promotion_quote_send_to_customer_without_email_fails_clearly(): void
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

        $quote = $this->createQuote('TK-HOTFIX-003');

        $response = $this->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.show', $quote))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), [
                'contact_name' => 'E-postasız Müşteri',
                'contact_email' => '',
                'contact_phone' => '05325550000',
            ]);

        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));
        $response->assertSessionHasErrors([
            'error' => 'Müşteri e-posta adresi olmadığı için teklif maili gönderilemedi.',
        ]);

        $this->assertSame(0, $quote->quoteApprovalRequests()->count());
        $this->assertSame(0, NotificationLog::query()->where('related_id', $quote->id)->count());
    }

    public function test_promotion_quote_send_to_customer_keeps_public_approval_link(): void
    {
        $this->seedSmtpSettings();
        $quote = $this->createQuote('TK-HOTFIX-004');
        $capturedUrl = null;

        $this->expectQuoteMailSend(1, function (QuoteCustomerApprovalMail $mail) use (&$capturedUrl): void {
            $capturedUrl = $mail->publicApprovalUrl;
        });

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), [
                'contact_name' => 'Ayşe Müşteri',
                'contact_email' => 'ayse@example.test',
                'contact_phone' => '05320000000',
            ])
            ->assertRedirect(route('admin.promotion-quotes.show', $quote));

        $request = $quote->fresh()->latestQuoteApprovalRequest;
        $this->assertNotNull($request);
        $this->assertSame(
            route('public.quotes.approval.show', ['token' => $request->token]),
            $capturedUrl
        );
    }

    public function test_promotion_quote_send_mail_does_not_leak_sensitive_data(): void
    {
        $this->seedSmtpSettings();
        $capturedMail = null;
        $this->expectQuoteMailSend(1, function (QuoteCustomerApprovalMail $mail) use (&$capturedMail): void {
            $capturedMail = $mail;
        });

        $quote = $this->createQuote('TK-HOTFIX-005');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), [
                'contact_name' => 'Ayşe Müşteri',
                'contact_email' => 'ayse@example.test',
                'contact_phone' => '05320000000',
            ])
            ->assertRedirect(route('admin.promotion-quotes.show', $quote));

        $this->assertInstanceOf(QuoteCustomerApprovalMail::class, $capturedMail);

        $html = view('emails.quote-customer-approval', [
            'tenant' => $capturedMail->tenant,
            'quote' => $capturedMail->quote,
            'customerName' => $capturedMail->customerName,
            'publicApprovalUrl' => $capturedMail->publicApprovalUrl,
            'validUntilLabel' => $capturedMail->validUntilLabel,
            'grandTotalLabel' => $capturedMail->grandTotalLabel,
        ])->render();

        $this->assertStringContainsString('TK-HOTFIX-005', $html);
        $this->assertStringContainsString('Ayşe Müşteri', $html);
        $this->assertStringNotContainsString('group_code', $html);
        $this->assertStringNotContainsString('supplier_cost', $html);
        $this->assertStringNotContainsString('subcontractor_cost', $html);
        $this->assertStringNotContainsString('profit', $html);
        $this->assertStringNotContainsString('file_path', $html);
        $this->assertStringNotContainsString('physical_path', $html);
        $this->assertStringNotContainsString('pdh_raw', $html);
    }

    public function test_promotion_quote_send_mail_failure_does_not_rollback_quote(): void
    {
        $this->seedSmtpSettings();

        Mail::shouldReceive('forgetMailers')->twice();
        Mail::shouldReceive('mailer')->once()->with('tenant_smtp_runtime')->andReturnSelf();
        Mail::shouldReceive('to')->once()->with('ayse@example.test')->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP auth failed with smtp_password secret token'));

        $quote = $this->createQuote('TK-HOTFIX-006');

        $response = $this->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.show', $quote))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), [
                'contact_name' => 'Ayşe Müşteri',
                'contact_email' => 'ayse@example.test',
                'contact_phone' => '05320000000',
            ]);

        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));
        $response->assertSessionHasErrors([
            'error' => 'Teklif kaydı oluşturuldu ancak e-posta gönderilemedi. SMTP ayarlarını veya müşteri e-posta adresini kontrol edin.',
        ]);

        $quote->refresh();
        $this->assertSame(Order::CUSTOMER_APPROVAL_WAITING, $quote->customer_approval_status);
        $this->assertNotNull($quote->latestQuoteApprovalRequest);

        $emailLog = NotificationLog::query()
            ->where('notification_key', 'quote_sent_to_customer')
            ->where('channel', NotificationLog::CHANNEL_EMAIL)
            ->where('related_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(NotificationLog::STATUS_FAILED, $emailLog->status);
        $this->assertSame('SMTP kimlik doğrulaması başarısız oldu.', $emailLog->error_message);
        $this->assertStringNotContainsString('secret', (string) $emailLog->error_message);
        $this->assertStringNotContainsString('token', (string) $emailLog->error_message);
    }

    public function test_promotion_quote_send_mail_uses_customer_facing_totals(): void
    {
        $this->seedSmtpSettings();
        $capturedMail = null;
        $this->expectQuoteMailSend(1, function (QuoteCustomerApprovalMail $mail) use (&$capturedMail): void {
            $capturedMail = $mail;
        });

        $quote = $this->createQuote('TK-HOTFIX-007');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), [
                'contact_name' => 'Ayşe Müşteri',
                'contact_email' => 'ayse@example.test',
                'contact_phone' => '05320000000',
            ])
            ->assertRedirect(route('admin.promotion-quotes.show', $quote));

        $this->assertInstanceOf(QuoteCustomerApprovalMail::class, $capturedMail);
        $this->assertSame('1.440,00 TL', $capturedMail->grandTotalLabel);

        $html = view('emails.quote-customer-approval', [
            'tenant' => $capturedMail->tenant,
            'quote' => $capturedMail->quote,
            'customerName' => $capturedMail->customerName,
            'publicApprovalUrl' => $capturedMail->publicApprovalUrl,
            'validUntilLabel' => $capturedMail->validUntilLabel,
            'grandTotalLabel' => $capturedMail->grandTotalLabel,
        ])->render();

        $this->assertStringContainsString('1.440,00 TL', $html);
    }

    private function expectQuoteMailSend(int $count, ?callable $assertion = null): void
    {
        Mail::shouldReceive('forgetMailers')->times($count * 2);
        Mail::shouldReceive('mailer')->times($count)->with('tenant_smtp_runtime')->andReturnSelf();
        Mail::shouldReceive('to')->times($count)->with('ayse@example.test')->andReturnSelf();
        Mail::shouldReceive('send')->times($count)->with(\Mockery::on(function ($mail) use ($assertion) {
            $this->assertInstanceOf(QuoteCustomerApprovalMail::class, $mail);

            if ($assertion) {
                $assertion($mail);
            }

            return true;
        }))->andReturnNull();
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
