<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QuoteApprovalRequest;
use App\Models\QuoteSendSnapshot;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\QuoteApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteApprovalAdminUiTest extends TestCase
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

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_demo_tenant_list_uses_simple_status_language_and_shows_send_action_without_module_required_banner(): void
    {
        $quote = $this->createQuote('TK-2026-4101');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.index'));

        $response->assertOk();
        $response->assertSee('Teklif');
        $response->assertSee('Onaylandı İşaretle');
        $response->assertSee('Müşteriye Gönder');
        $response->assertDontSee('Modül Gerekli');
        $response->assertDontSee('Taslak');
        $response->assertDontSee('snapshot_json', false);
        $response->assertDontSee('quote_send_snapshot_id', false);
        $response->assertDontSee('group_code', false);
        $response->assertSee('data-testid="quote-' . $quote->id . '-action-mark-approved"', false);
        $response->assertSee('data-testid="quote-' . $quote->id . '-action-send-customer"', false);
    }

    public function test_list_shows_send_and_resend_actions_when_customer_quote_approval_module_is_enabled(): void
    {
        $service = app(QuoteApprovalService::class);
        $preparedQuote = $this->createQuote('TK-2026-4102');
        $waitingQuote = $this->createQuote('TK-2026-4103');

        $this->enableCustomerQuoteApprovalModule();
        $service->sendToCustomer($waitingQuote, ['contact_email' => 'waiting@example.test'], $this->adminUser);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.index'));

        $response->assertOk();
        $response->assertSee('Müşteriye Gönder');
        $response->assertSee('Tekrar Gönder');
        $response->assertSee('Onay Bekliyor');
        $response->assertSee('data-testid="quote-' . $preparedQuote->id . '-action-send-customer"', false);
        $response->assertSee('data-testid="quote-' . $waitingQuote->id . '-action-send-customer"', false);
    }

    public function test_mark_approved_action_works_without_customer_approval_module(): void
    {
        $quote = $this->createQuote('TK-2026-4104');

        $response = $this->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.show', $quote))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.mark-approved', $quote));

        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));
        $response->assertSessionHas('success', 'Teklif onaylandı olarak işaretlendi.');

        $quote->refresh();
        $this->assertSame('approved', $quote->status);
        $this->assertSame(Order::CUSTOMER_APPROVAL_APPROVED, $quote->customer_approval_status);
        $this->assertSame(Order::CUSTOMER_APPROVAL_SOURCE_INTERNAL_MANUAL, $quote->customer_approval_source);
        $this->assertNotNull($quote->approved_at);
    }

    public function test_send_to_customer_creates_snapshot_request_and_resend_cancels_open_request_when_module_is_enabled(): void
    {
        $quote = $this->createQuote('TK-2026-4105');
        $this->enableCustomerQuoteApprovalModule();

        $firstResponse = $this->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.show', $quote))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), [
                'contact_name' => 'Ayşe Yıldız',
                'contact_email' => 'ayse@example.test',
                'contact_phone' => '05320000001',
                'expires_in_days' => 7,
                'sent_channel' => 'manual',
            ]);

        $firstResponse->assertRedirect(route('admin.promotion-quotes.show', $quote));
        $firstResponse->assertSessionHas('success', 'Teklif müşteriye gönderime hazırlandı.');

        $quote->refresh();
        $firstRequest = $quote->quoteApprovalRequests()->latest('id')->firstOrFail();
        $firstSnapshot = $quote->quoteSendSnapshots()->latest('id')->firstOrFail();

        $this->assertSame(Order::CUSTOMER_APPROVAL_WAITING, $quote->customer_approval_status);
        $this->assertSame(QuoteApprovalRequest::STATUS_WAITING, $firstRequest->status);
        $this->assertSame($firstSnapshot->id, $firstRequest->quote_send_snapshot_id);
        $this->assertSame(1, $firstSnapshot->send_no);

        $secondResponse = $this->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.show', $quote))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), [
                'contact_name' => 'Ayşe Yıldız',
                'contact_email' => 'ayse@example.test',
                'contact_phone' => '05320000001',
                'expires_in_days' => 7,
                'sent_channel' => 'manual',
            ]);

        $secondResponse->assertRedirect(route('admin.promotion-quotes.show', $quote));

        $quote->refresh();
        $secondRequest = $quote->quoteApprovalRequests()->latest('id')->firstOrFail();
        $secondSnapshot = $quote->quoteSendSnapshots()->latest('id')->firstOrFail();

        $this->assertSame(2, $secondSnapshot->send_no);
        $this->assertSame(QuoteApprovalRequest::STATUS_CANCELLED, $firstRequest->fresh()->status);
        $this->assertSame(QuoteApprovalRequest::STATUS_WAITING, $secondRequest->status);
        $this->assertSame($secondSnapshot->id, $secondRequest->quote_send_snapshot_id);
    }

    public function test_send_to_customer_is_forbidden_when_module_is_disabled(): void
    {
        $this->tenant->forceFill([
            'package_key' => 'starter',
            'panel_subdomain' => 'quote-approval-guarded',
            'slug' => 'quote-approval-guarded',
        ])->save();

        $quote = $this->createQuote('TK-2026-4106');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), [
                'contact_email' => 'blocked@example.test',
            ])
            ->assertForbidden();

        $this->assertSame(0, QuoteSendSnapshot::query()->count());
        $this->assertSame(0, QuoteApprovalRequest::query()->count());
    }

    public function test_show_screen_displays_simple_customer_response_send_history_and_hides_technical_fields(): void
    {
        $service = app(QuoteApprovalService::class);
        $quote = $this->createQuote('TK-2026-4107');
        $this->enableCustomerQuoteApprovalModule();

        $request = $service->sendToCustomer($quote, ['contact_email' => 'revize@example.test'], $this->adminUser);
        $service->requestRevision($request, 'Logo rengini mavi yapalım.');
        $request->refresh();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote->fresh()));

        $response->assertOk();
        $response->assertSee('Revize İstendi');
        $response->assertSee('Müşteri revize istedi');
        $response->assertSee('Logo rengini mavi yapalım.');
        $response->assertSee('Gönderim Geçmişi');
        $response->assertSee('Tekrar Gönder');
        $response->assertSee('Onaylandı İşaretle');
        $response->assertDontSee('snapshot_json', false);
        $response->assertDontSee($request->token, false);
        $response->assertDontSee('quote_send_snapshot_id', false);
        $response->assertDontSee('approval_request_id', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('raw_price_snapshot', false);
    }

    public function test_tenant_scope_and_permissions_are_enforced_for_mark_approved_and_send_to_customer(): void
    {
        $foreignQuote = $this->createForeignTenantQuote();
        $this->enableCustomerQuoteApprovalModule();

        $markApprovedResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.mark-approved', $foreignQuote));

        $this->assertContains($markApprovedResponse->getStatusCode(), [403, 404]);

        $sendResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $foreignQuote), [
                'contact_email' => 'foreign@example.test',
            ]);

        $this->assertContains($sendResponse->getStatusCode(), [403, 404]);

        $unauthorizedUser = User::factory()->create([
            'name' => 'Yetkisiz Kullanıcı',
            'email' => 'unauthorized.quote@prodelya.local',
            'password' => 'password',
        ]);

        $localQuote = $this->createQuote('TK-2026-4108');

        $unauthorizedMarkApprovedResponse = $this->actingAs($unauthorizedUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.mark-approved', $localQuote));

        $this->assertContains($unauthorizedMarkApprovedResponse->getStatusCode(), [403, 404]);

        $unauthorizedSendResponse = $this->actingAs($unauthorizedUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $localQuote), [
                'contact_email' => 'local@example.test',
            ]);

        $this->assertContains($unauthorizedSendResponse->getStatusCode(), [403, 404]);
    }

    public function test_converted_quote_cannot_be_marked_approved_or_sent_again(): void
    {
        $quote = $this->createQuote('TK-2026-4109', [
            'status' => 'approved',
            'workflow_status' => 'quote_converted',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_APPROVED,
        ]);
        $this->enableCustomerQuoteApprovalModule();

        $markApprovedResponse = $this->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.show', $quote))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.mark-approved', $quote));

        $markApprovedResponse->assertRedirect(route('admin.promotion-quotes.show', $quote));
        $markApprovedResponse->assertSessionHasErrors('error');

        $sendResponse = $this->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.show', $quote))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), [
                'contact_email' => 'converted@example.test',
            ]);

        $sendResponse->assertRedirect(route('admin.promotion-quotes.show', $quote));
        $sendResponse->assertSessionHasErrors('error');
        $this->assertSame(0, $quote->quoteSendSnapshots()->count());
        $this->assertSame(0, $quote->quoteApprovalRequests()->count());
    }

    public function test_real_sources_remain_untouched_and_no_tmp_demo_records_are_created(): void
    {
        $quote = $this->createQuote('TK-2026-4110');
        $this->enableCustomerQuoteApprovalModule();

        $beforeNames = Supplier::query()->orderBy('name')->pluck('name')->all();

        $this->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.show', $quote))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.send-to-customer', $quote), [
                'contact_email' => 'sources@example.test',
            ])
            ->assertRedirect();

        $afterNames = Supplier::query()->orderBy('name')->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['Akdeniz Promosyon', 'Etkin Promosyon', 'İlpen', 'Yeni Nesil'], $beforeNames);
        $this->assertSame($beforeNames, $afterNames);
        $this->assertSame(0, Supplier::query()->where('name', 'like', 'TMP%')->count());
        $this->assertSame(0, Supplier::query()->where('name', 'like', 'DEMO%')->count());
    }

    private function enableCustomerQuoteApprovalModule(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'customer_quote_approval',
                'feature_key' => 'customer_quote_approval',
            ],
            [
                'is_enabled' => true,
                'meta' => ['source' => 'test'],
            ]
        );
    }

    private function createQuote(string $documentNumber, array $overrides = []): Order
    {
        $quote = Order::query()->create(array_merge([
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
        ], $overrides));

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'UI Test Ürünü',
            'product_code' => 'UIT-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'UI teklif kalemi',
            'product_snapshot' => ['display_name' => 'UI Test Ürünü'],
            'price_snapshot' => [
                'product_total' => 1200,
                'vat_rate' => 20,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 240, 'scope' => 'product'],
                ],
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

    private function createForeignTenantQuote(): Order
    {
        $tenant = TenantAccount::query()->create([
            'name' => 'Dış Tenant Teklif UI',
            'legal_name' => 'Dış Tenant Teklif UI Ltd. Şti.',
            'slug' => 'dis-tenant-teklif-ui',
            'panel_subdomain' => 'distenantteklifui',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Tenant Dışı Teklif Müşteri',
            'status' => 'active',
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'company_id' => $company->id,
            'role_key' => 'customer',
        ]);

        $quote = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-2026-9904',
            'customer_company_id' => $company->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
            'quote_date' => '2026-06-15',
            'invoice_status' => 'fis',
            'currency' => 'TL',
            'subtotal' => 900,
            'vat_total' => 0,
            'grand_total' => 900,
            'product_total' => 900,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Dış Tenant Ürünü',
            'product_code' => 'DIS-001',
            'quantity' => 50,
            'unit' => 'Adet',
            'line_total' => 900,
            'unit_price' => 18,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        return $quote->fresh();
    }
}
