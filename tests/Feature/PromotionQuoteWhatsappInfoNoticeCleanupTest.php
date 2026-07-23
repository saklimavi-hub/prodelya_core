<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteWhatsappInfoNoticeCleanupTest extends TestCase
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

        $this->enableWhatsappAccess();
    }

    public function test_whatsapp_info_notice_is_not_rendered_on_quote_detail_page()
    {
        $quote = $this->createQuote([
            'document_number' => 'TK-WA-001',
            'currency' => 'TRY',
            'status' => 'draft',
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost()])
            ->get(route('admin.promotion-quotes.show', $quote))
            ->assertOk()
            ->assertDontSee('WhatsApp Link için e-posta zorunlu değildir. Telefon alanı yeterliyse güvenli gönderim bağlantısı hazırlanır.')
            ->assertDontSee('data-testid="quote-guide-notice"', false);
    }

    public function test_whatsapp_info_notice_is_not_rendered_for_any_quote_status()
    {
        $statuses = ['draft', 'sent', 'approved', 'converted'];

        foreach ($statuses as $status) {
            $quote = $this->createQuote([
                'document_number' => 'TK-WA-' . strtoupper($status),
                'currency' => 'TRY',
                'status' => $status,
            ]);

            $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => $this->tenantHost()])
                ->get(route('admin.promotion-quotes.show', $quote))
                ->assertOk()
                ->assertDontSee('WhatsApp Link için e-posta zorunlu değildir. Telefon alanı yeterliyse güvenli gönderim bağlantısı hazırlanır.')
                ->assertDontSee('data-testid="quote-guide-notice"', false);
        }
    }

    public function test_whatsapp_info_notice_is_not_rendered_for_foreign_currencies()
    {
        $currencies = ['USD', 'EUR'];

        foreach ($currencies as $currency) {
            $quote = $this->createQuote([
                'document_number' => 'TK-WA-' . $currency,
                'currency' => $currency,
                'status' => 'draft',
            ]);

            $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => $this->tenantHost()])
                ->get(route('admin.promotion-quotes.show', $quote))
                ->assertOk()
                ->assertDontSee('WhatsApp Link için e-posta zorunlu değildir. Telefon alanı yeterliyse güvenli gönderim bağlantısı hazırlanır.')
                ->assertDontSee('data-testid="quote-guide-notice"', false);
        }
    }

    public function test_whatsapp_buttons_and_functionality_remain_intact()
    {
        $quote = $this->createQuote([
            'document_number' => 'TK-WA-BTN-001',
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost()])
            ->get(route('admin.promotion-quotes.show', $quote))
            ->assertOk()
            ->assertSee('WhatsApp');
    }

    public function test_phone_field_still_works_for_whatsapp_without_email_requirement()
    {
        $customer = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'WhatsApp Phone Test Company',
            'short_name' => 'WA Phone',
            'phone' => '+905551234567',
            'status' => 'active',
        ]);

        $quote = $this->createQuote([
            'document_number' => 'TK-WA-PHONE-001',
            'customer_company_id' => $customer->id,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost()])
            ->get(route('admin.promotion-quotes.show', $quote))
            ->assertOk()
            ->assertSee('0555 123 45 67');
    }

    private function createQuote(array $attributes = []): Order
    {
        $quote = Order::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-WA-BASE-001',
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
            'quote_date' => '2026-07-11',
            'valid_until' => '2026-07-18',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TRY',
            'subtotal' => 500,
            'vat_total' => 100,
            'grand_total' => 600,
            'product_total' => 500,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
        ], $attributes));

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'WhatsApp Notice Test Product',
            'product_code' => 'WA-NOTICE-001',
            'quantity' => 5,
            'unit' => 'Adet',
            'unit_price' => 100,
            'line_total' => 500,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
            'product_snapshot' => ['product_name' => 'WhatsApp Notice Test Product'],
            'price_snapshot' => [
                'document_currency' => $attributes['currency'] ?? 'TRY',
                'actual_sales_unit_price_document' => 100,
                'product_line_total_document' => 500,
            ],
        ]);

        return $quote->fresh();
    }

    private function enableWhatsappAccess(): void
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

    private function tenantHost(): string
    {
        return $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST;
    }
}
