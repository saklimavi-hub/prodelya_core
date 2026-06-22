<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QuoteApprovalRequest;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use App\Services\QuoteApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicQuoteApprovalUiStatusTest extends TestCase
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

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => 'public_quote_approval',
            ],
            ['is_enabled' => true]
        );
    }

    public function test_public_quote_ui_status_messages_validation_and_failure_safety_work_cleanly(): void
    {
        $quote = $this->createQuote('TK-PUBLIC-UI-001');
        $approvalRequest = app(QuoteApprovalService::class)->sendToCustomer($quote, [
            'contact_email' => 'status@example.test',
        ], $this->adminUser);

        $showResponse = $this->get(route('public.quotes.approval.show', ['token' => $approvalRequest->token]));
        $showResponse->assertOk();
        $showResponse->assertSee('Teklifinizi İnceleyin');
        $showResponse->assertSee('Teklifi Onayla');
        $showResponse->assertSee('Revize İste');
        $showResponse->assertSee('Teklifi Reddet');
        $showResponse->assertSee('Status Test Uruenu');
        $showResponse->assertSee('1.200,00 TL');
        $showResponse->assertSee('240,00 TL');
        $showResponse->assertSee('1.440,00 TL');
        $showResponse->assertDontSee('name="token"', false);
        $showResponse->assertDontSee('purchase_total', false);
        $showResponse->assertDontSee('subcontractor_cost', false);
        $showResponse->assertDontSee('setup_cost', false);
        $showResponse->assertDontSee('balance_due', false);
        $showResponse->assertDontSee('notification_logs', false);
        $showResponse->assertDontSee('group_code', false);
        $showResponse->assertDontSee('pdh_raw', false);
        $showResponse->assertDontSee('file_path', false);
        $showResponse->assertDontSee('physical_path', false);
        $showResponse->assertDontSee('internal note', false);

        $this->post(route('public.quotes.approval.revision', ['token' => $approvalRequest->token]), [
            'customer_note' => 'ab',
        ])->assertSessionHasErrors('customer_note');

        $revisionQuote = $this->createQuote('TK-PUBLIC-UI-002');
        $revisionRequest = app(QuoteApprovalService::class)->sendToCustomer($revisionQuote, [
            'contact_email' => 'revision-ui@example.test',
        ], $this->adminUser);

        $revisionResponse = $this->followingRedirects()
            ->post(route('public.quotes.approval.revision', ['token' => $revisionRequest->token]), [
                'customer_note' => 'Termin bir gun ileri olsun.',
            ]);
        $revisionResponse->assertOk();
        $revisionResponse->assertSee('Revize talebiniz iletilmiştir.');
        $revisionResponse->assertSee('Bu teklif için revize talebi iletilmiş.');
        $this->assertSame(QuoteApprovalRequest::STATUS_REVISION_REQUESTED, $revisionRequest->fresh()->status);

        $rejectedQuote = $this->createQuote('TK-PUBLIC-UI-003');
        $rejectedRequest = app(QuoteApprovalService::class)->sendToCustomer($rejectedQuote, [
            'contact_email' => 'reject-ui@example.test',
        ], $this->adminUser);
        app(QuoteApprovalService::class)->reject($rejectedRequest, 'Uygun degil');

        $rejectedResponse = $this->followingRedirects()
            ->post(route('public.quotes.approval.reject', ['token' => $rejectedRequest->token]), [
                'customer_note' => 'Tekrar deneme',
            ]);
        $rejectedResponse->assertOk();
        $rejectedResponse->assertSee('Bu teklif daha önce reddedilmiş.');

        $approvedQuote = $this->createQuote('TK-PUBLIC-UI-004');
        $approvedRequest = app(QuoteApprovalService::class)->sendToCustomer($approvedQuote, [
            'contact_email' => 'approve-ui@example.test',
        ], $this->adminUser);
        $approvedResponse = $this->followingRedirects()
            ->post(route('public.quotes.approval.approve', ['token' => $approvedRequest->token]), [
                'customer_note' => '',
            ]);
        $approvedResponse->assertOk();
        $approvedResponse->assertSee('Teklif onayınız alınmıştır.');
        $approvedResponse->assertSee('Bu teklif daha önce onaylanmış.');

        $expiredQuote = $this->createQuote('TK-PUBLIC-UI-005');
        $expiredRequest = app(QuoteApprovalService::class)->sendToCustomer($expiredQuote, [
            'contact_email' => 'expired-ui@example.test',
            'expires_at' => now()->subMinute(),
        ], $this->adminUser);

        $this->get(route('public.quotes.approval.show', ['token' => $expiredRequest->token]))
            ->assertOk()
            ->assertSee('Bu teklif bağlantısının süresi dolmuş.');

        $failingNotificationService = $this->createMock(NotificationEventService::class);
        $failingNotificationService->method('dispatchEvent')
            ->willThrowException(new \RuntimeException('notification failure'));
        $this->app->instance(NotificationEventService::class, $failingNotificationService);

        $failureSafeQuote = $this->createQuote('TK-PUBLIC-UI-006');
        $failureSafeRequest = app(QuoteApprovalService::class)->sendToCustomer($failureSafeQuote, [
            'contact_email' => 'safe-ui@example.test',
        ], $this->adminUser);

        $failureSafeResponse = $this->followingRedirects()
            ->post(route('public.quotes.approval.approve', ['token' => $failureSafeRequest->token]), [
                'customer_note' => '',
            ]);

        $failureSafeResponse->assertOk();
        $failureSafeResponse->assertSee('Teklif onayınız alınmıştır.');
        $this->assertSame(QuoteApprovalRequest::STATUS_APPROVED, $failureSafeRequest->fresh()->status);

        $this->get(route('public.work-forms.track', ['token' => 'missing-token']))
            ->assertNotFound();
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
            'notes' => 'Musteri icin guvenli not',
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
            'product_name' => 'Status Test Uruenu',
            'product_code' => 'PQA-STATUS-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Musteri icin guvenli aciklama',
            'product_snapshot' => ['display_name' => 'Status Test Uruenu'],
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
            'has_print' => true,
            'print_total' => 0,
            'status' => 'pending',
            'print_operations_snapshot' => [
                [
                    'print_type' => 'Selefon Baski',
                    'print_option' => 'Mat',
                    'quantity' => 100,
                    'note' => 'Musteri gorunur baski notu',
                ],
            ],
        ]);

        return $quote->fresh();
    }
}
