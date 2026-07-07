<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\QuoteApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteSendActionsUxTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';
    private const STARTER_HOST = 'quote-send-starter.prodelya.test';

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

    public function test_detail_shows_send_actions_card_for_not_sent_quote_and_links_real_pdf_route_safely(): void
    {
        $this->enableQuoteApprovalFeatures();

        $quote = $this->createQuote('TK-SEND-7001');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote));

        $response->assertOk();
        $response->assertSee('Gönderim Aksiyonları');
        $response->assertSee('Müşteriye Gönder');
        $response->assertSee(route('admin.promotion-quotes.send-to-customer', $quote), false);
        $response->assertSee('PDF Teklif');
        $response->assertSee(route('admin.promotion-quotes.pdf', $quote), false);
        $response->assertDontSee('Onay Linkini Aç');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('notification_logs', false);
    }

    public function test_detail_shows_resend_open_link_and_working_whatsapp_helper_without_leaking_token(): void
    {
        $this->enableQuoteApprovalFeatures();
        $this->enableWhatsappFeatures();

        $quote = $this->createQuote('TK-SEND-7002');
        $request = app(QuoteApprovalService::class)->sendToCustomer($quote, [
            'contact_name' => 'Ayşe Müşteri',
            'contact_email' => 'ayse@example.test',
            'contact_phone' => '05320000000',
            'sent_channel' => 'manual',
        ], $this->adminUser);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote->fresh()));

        $response->assertOk();
        $response->assertSee('Gönderim Aksiyonları');
        $response->assertSee('Tekrar Gönder');
        $response->assertSee('Onay Linkini Aç');
        $response->assertSee('WhatsApp Gönder');
        $response->assertSee(route('admin.promotion-quotes.whatsapp.open', $quote), false);
        $response->assertDontSee($request->token, false);

        $whatsAppResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.whatsapp.open', $quote->fresh()));

        $whatsAppResponse->assertRedirect();
        $this->assertStringStartsWith('https://wa.me/', (string) $whatsAppResponse->headers->get('Location'));

        $log = NotificationLog::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('notification_key', 'whatsapp_manual_link')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(NotificationLog::STATUS_LINK_CREATED, $log->status);
    }

    public function test_send_action_redirects_back_with_visible_runtime_summary_and_success_flash(): void
    {
        $this->enableQuoteApprovalFeatures();
        $this->enableWhatsappFeatures();

        $quote = $this->createQuote('TK-SEND-7002A');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->followingRedirects()
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), [
                'contact_name' => 'Ayşe Müşteri',
                'contact_email' => 'ayse@example.test',
                'contact_phone' => '05320000000',
                'sent_channel' => 'manual',
            ]);

        $response->assertOk();
        $response->assertSee('Gönderim kaydı oluşturuldu.');
        $response->assertSee('Son Oluşturulan Kayıtlar');
        $response->assertSee('E-posta: Atlandı');
        $response->assertSee('WhatsApp: Link Oluşturuldu');
        $response->assertSee('İç Kayıt: Gönderildi');
        $response->assertSee('Kanal veya hedef kitle ayarları nedeniyle bildirim atlandı.');
    }

    public function test_detail_shows_safe_whatsapp_disabled_state_when_phone_is_missing(): void
    {
        $this->enableQuoteApprovalFeatures();
        $this->enableWhatsappFeatures();

        $this->customer->forceFill([
            'phone' => null,
            'mobile' => null,
        ])->save();

        $quote = $this->createQuote('TK-SEND-7003');
        app(QuoteApprovalService::class)->sendToCustomer($quote, [
            'contact_name' => 'Telefonsuz Müşteri',
            'contact_email' => 'no-phone@example.test',
            'contact_phone' => null,
            'sent_channel' => 'manual',
        ], $this->adminUser);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote->fresh()));

        $response->assertOk();
        $response->assertSee('Telefon yok');
        $response->assertSee('data-testid="quote-whatsapp-send-disabled"', false);
    }

    public function test_detail_hides_send_and_open_link_actions_when_quote_approval_feature_is_closed(): void
    {
        $starterTenant = TenantAccount::query()->create([
            'name' => 'Quote Send Starter Tenant',
            'legal_name' => 'Quote Send Starter Tenant Ltd.',
            'slug' => 'quote-send-starter',
            'panel_subdomain' => 'quote-send-starter',
            'status' => 'active',
            'package_key' => 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        UserRole::query()->firstOrCreate([
            'user_id' => $this->adminUser->id,
            'role_id' => Role::query()->where('key', 'admin')->value('id'),
            'tenant_account_id' => $starterTenant->id,
        ]);

        $starterCustomer = Company::query()->create([
            'tenant_account_id' => $starterTenant->id,
            'legal_name' => 'Starter Quote Customer',
            'status' => 'active',
        ]);

        $quote = $this->createQuote('TK-SEND-7004', $starterTenant, $starterCustomer);

        $response = $this->actingAs($this->adminUser)
            ->get('http://' . self::STARTER_HOST . '/admin/promotion-quotes/' . $quote->id);

        $response->assertOk();
        $response->assertSee('Gönderim Aksiyonları');
        $response->assertDontSee('Müşteriye Gönder');
        $response->assertDontSee('Tekrar Gönder');
        $response->assertDontSee('Onay Linkini Aç');
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
    }

    private function createQuote(string $documentNumber, ?TenantAccount $tenant = null, ?Company $customer = null): Order
    {
        $tenant ??= $this->tenant;
        $customer ??= $this->customer;

        $quote = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => $documentNumber,
            'customer_company_id' => $customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
            'quote_date' => '2026-06-19',
            'valid_until' => '2026-06-26',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 1200,
            'vat_total' => 240,
            'grand_total' => 1440,
            'product_total' => 1200,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
            'notes' => 'Müşteriye gösterilebilir teklif notu',
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Send Action Test Ürünü',
            'product_code' => 'SAT-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Gönderim aksiyonu test kalemi',
            'product_snapshot' => ['display_name' => 'Send Action Test Ürünü'],
            'price_snapshot' => [
                'product_total' => 1200,
                'vat_rate' => 20,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 240, 'scope' => 'product'],
                ],
                'group_code' => 'SECRET-GROUP',
                'file_path' => '/hidden/quote.pdf',
                'purchase_total' => 300,
                'supplier_cost' => 400,
                'subcontractor_cost' => 500,
                'setup_cost' => 600,
                'profit' => 700,
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
