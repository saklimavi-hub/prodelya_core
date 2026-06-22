<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QuoteApprovalRequest;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\QuoteApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class PublicQuoteApprovalRouteTest extends TestCase
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
        $this->tenant->forceFill([
            'package_key' => 'starter',
            'panel_subdomain' => 'public-quote-guarded',
            'slug' => 'public-quote-guarded',
        ])->save();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        TenantSetting::setValue($this->tenant->id, 'internal_notification_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'notification_email_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'customer_notification_enabled', true, 'boolean');
        $this->enablePublicQuoteApproval();
    }

    public function test_valid_token_show_marks_request_as_viewed_and_public_url_is_resolved(): void
    {
        $quote = $this->createQuote('TK-PUBLIC-001');
        $service = app(QuoteApprovalService::class);
        $approvalRequest = $service->sendToCustomer($quote, [
            'contact_email' => 'musteri@example.test',
        ], $this->adminUser);

        $this->assertSame(QuoteApprovalRequest::STATUS_WAITING, $approvalRequest->status);

        $response = $this->get(route('public.quotes.approval.show', ['token' => $approvalRequest->token]));

        $response->assertOk();
        $response->assertSee($quote->document_number);
        $response->assertSee('Teklifi Onayla');
        $this->assertSame(QuoteApprovalRequest::STATUS_VIEWED, $approvalRequest->fresh()->status);

        $method = new ReflectionMethod($service, 'resolvePublicQuoteUrl');
        $method->setAccessible(true);
        $resolvedUrl = $method->invoke($service, $approvalRequest->fresh());

        $this->assertSame(route('public.quotes.approval.show', ['token' => $approvalRequest->token]), $resolvedUrl);
    }

    public function test_approve_revision_and_reject_actions_use_service_and_emit_notifications(): void
    {
        $approveQuote = $this->createQuote('TK-PUBLIC-002');
        $approveRequest = app(QuoteApprovalService::class)->sendToCustomer($approveQuote, [
            'contact_email' => 'approve@example.test',
        ], $this->adminUser);

        $approveResponse = $this->post(route('public.quotes.approval.approve', ['token' => $approveRequest->token]), [
            'customer_note' => 'Teklif uygundur.',
        ]);

        $approveResponse->assertRedirect(route('public.quotes.approval.show', ['token' => $approveRequest->token]));
        $approveResponse->assertSessionHas('success', 'Teklif onayınız alınmıştır.');
        $this->assertSame(QuoteApprovalRequest::STATUS_APPROVED, $approveRequest->fresh()->status);
        $this->assertTrue(NotificationLog::query()
            ->where('notification_key', 'quote_customer_approved')
            ->where('status', NotificationLog::STATUS_SENT)
            ->exists());

        $revisionQuote = $this->createQuote('TK-PUBLIC-003');
        $revisionRequest = app(QuoteApprovalService::class)->sendToCustomer($revisionQuote, [
            'contact_email' => 'revision@example.test',
        ], $this->adminUser);

        $revisionResponse = $this->post(route('public.quotes.approval.revision', ['token' => $revisionRequest->token]), [
            'customer_note' => 'Tarih ve baskı notu revize olsun.',
        ]);

        $revisionResponse->assertRedirect(route('public.quotes.approval.show', ['token' => $revisionRequest->token]));
        $revisionResponse->assertSessionHas('success', 'Revize talebiniz iletilmiştir.');
        $this->assertSame(QuoteApprovalRequest::STATUS_REVISION_REQUESTED, $revisionRequest->fresh()->status);
        $this->assertTrue(NotificationLog::query()
            ->where('notification_key', 'quote_revision_requested')
            ->where('status', NotificationLog::STATUS_SENT)
            ->exists());

        $rejectQuote = $this->createQuote('TK-PUBLIC-004');
        $rejectRequest = app(QuoteApprovalService::class)->sendToCustomer($rejectQuote, [
            'contact_email' => 'reject@example.test',
        ], $this->adminUser);

        $rejectResponse = $this->post(route('public.quotes.approval.reject', ['token' => $rejectRequest->token]), [
            'customer_note' => 'Bu hali uygun değil.',
        ]);

        $rejectResponse->assertRedirect(route('public.quotes.approval.show', ['token' => $rejectRequest->token]));
        $rejectResponse->assertSessionHas('success', 'Teklif reddi kaydedilmiştir.');
        $this->assertSame(QuoteApprovalRequest::STATUS_REJECTED, $rejectRequest->fresh()->status);
        $this->assertTrue(NotificationLog::query()
            ->where('notification_key', 'quote_rejected')
            ->where('status', NotificationLog::STATUS_SENT)
            ->exists());
    }

    public function test_invalid_cancelled_processed_and_feature_disabled_tokens_are_safely_closed(): void
    {
        $this->get(route('public.quotes.approval.show', ['token' => 'gecersiz-token']))
            ->assertNotFound();

        $cancelledQuote = $this->createQuote('TK-PUBLIC-005');
        $cancelledRequest = app(QuoteApprovalService::class)->sendToCustomer($cancelledQuote, [
            'contact_email' => 'cancelled@example.test',
        ], $this->adminUser);
        $cancelledRequest->forceFill(['status' => QuoteApprovalRequest::STATUS_CANCELLED])->save();

        $this->get(route('public.quotes.approval.show', ['token' => $cancelledRequest->token]))
            ->assertNotFound();

        $approvedQuote = $this->createQuote('TK-PUBLIC-006');
        $approvedRequest = app(QuoteApprovalService::class)->sendToCustomer($approvedQuote, [
            'contact_email' => 'approved@example.test',
        ], $this->adminUser);
        app(QuoteApprovalService::class)->approve($approvedRequest, 'Tamam');

        $processedResponse = $this->followingRedirects()
            ->post(route('public.quotes.approval.approve', ['token' => $approvedRequest->token]), [
                'customer_note' => 'Tekrar onay',
            ]);

        $processedResponse->assertOk();
        $processedResponse->assertSee('Bu teklif daha önce onaylanmış.');

        $expiredQuote = $this->createQuote('TK-PUBLIC-007');
        $expiredRequest = app(QuoteApprovalService::class)->sendToCustomer($expiredQuote, [
            'contact_email' => 'expired@example.test',
            'expires_at' => now()->subMinute(),
        ], $this->adminUser);

        $expiredResponse = $this->get(route('public.quotes.approval.show', ['token' => $expiredRequest->token]));
        $expiredResponse->assertOk();
        $expiredResponse->assertSee('Bu teklif bağlantısının süresi dolmuş.');

        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'quote_customer_approval')
            ->delete();

        $featureClosedQuote = $this->createQuote('TK-PUBLIC-008');
        $featureClosedRequest = app(QuoteApprovalService::class)->sendToCustomer($featureClosedQuote, [
            'contact_email' => 'disabled@example.test',
        ], $this->adminUser);

        $this->get(route('public.quotes.approval.show', ['token' => $featureClosedRequest->token]))
            ->assertNotFound();
    }

    private function enablePublicQuoteApproval(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => 'public_quote_approval',
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
            'quote_date' => '2026-06-18',
            'valid_until' => '2026-06-25',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'notes' => 'Public quote approval test',
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
            'product_name' => 'Public Quote Ürünü',
            'product_code' => 'PQA-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Customer-safe quote item',
            'product_snapshot' => ['display_name' => 'Public Quote Ürünü'],
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
}
