<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\QuoteApprovalRequest;
use App\Models\QuoteSendSnapshot;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\QuoteApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class QuoteApprovalCoreTest extends TestCase
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

    public function test_snapshot_and_approval_request_tables_models_and_columns_are_available(): void
    {
        $this->assertTrue(Schema::hasTable('quote_send_snapshots'));
        $this->assertTrue(Schema::hasTable('quote_approval_requests'));
        $this->assertTrue(Schema::hasColumn('orders', 'customer_approval_status'));
        $this->assertTrue(Schema::hasColumn('orders', 'last_sent_at'));
        $this->assertTrue(Schema::hasColumn('orders', 'approved_at'));
        $this->assertTrue(Schema::hasColumn('orders', 'rejected_at'));
        $this->assertTrue(Schema::hasColumn('orders', 'revision_requested_at'));

        $quote = $this->createQuote();

        $snapshot = QuoteSendSnapshot::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'quote_id' => $quote->id,
            'send_no' => 1,
            'snapshot_json' => ['quote_number' => $quote->document_number],
            'summary_json' => ['customer_name' => $this->customer->legal_name],
            'financial_snapshot_json' => ['grand_total' => 1200],
            'sent_channel' => QuoteSendSnapshot::CHANNEL_EMAIL,
            'sent_at' => now(),
            'created_by' => $this->adminUser->id,
        ]);

        $request = QuoteApprovalRequest::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'quote_id' => $quote->id,
            'quote_send_snapshot_id' => $snapshot->id,
            'customer_company_id' => $this->customer->id,
            'contact_name' => 'Satın Alma',
            'contact_email' => 'musteri@example.test',
            'token' => 'token-core-test',
            'status' => QuoteApprovalRequest::STATUS_WAITING,
            'created_by' => $this->adminUser->id,
        ]);

        $this->assertSame($quote->id, $snapshot->quote?->id);
        $this->assertSame($this->tenant->id, $snapshot->tenant?->id);
        $this->assertSame('E-posta', $snapshot->safeSendLabel());
        $this->assertTrue($snapshot->isSent());
        $this->assertStringContainsString($quote->document_number, $snapshot->publicReferenceLabel());

        $this->assertSame($quote->id, $request->quote?->id);
        $this->assertSame($snapshot->id, $request->sendSnapshot?->id);
        $this->assertSame($this->customer->id, $request->customerCompany?->id);
        $this->assertTrue($request->isWaiting());
        $this->assertTrue($request->isActive());
        $this->assertSame('Bekliyor', $request->safeStatusLabel());

        $this->assertSame($snapshot->id, $quote->latestQuoteSendSnapshot?->id);
        $this->assertSame($request->id, $quote->latestQuoteApprovalRequest?->id);
    }

    public function test_send_to_customer_creates_snapshot_request_increments_send_number_and_updates_quote(): void
    {
        $service = app(QuoteApprovalService::class);
        $quote = $this->createQuote();

        $first = $service->sendToCustomer($quote, [
            'contact_name' => 'Ayşe Yıldız',
            'contact_email' => 'ayse@example.test',
            'contact_phone' => '05320000001',
            'sent_channel' => QuoteSendSnapshot::CHANNEL_EMAIL,
            'expires_in_days' => 7,
        ], $this->adminUser);

        $quote->refresh();
        $snapshotOne = $first->sendSnapshot()->firstOrFail();

        $this->assertSame(1, $snapshotOne->send_no);
        $this->assertSame(Order::CUSTOMER_APPROVAL_WAITING, $quote->customer_approval_status);
        $this->assertSame('pending', $quote->status);
        $this->assertNotNull($quote->last_sent_at);
        $this->assertTrue($first->isWaiting());
        $this->assertSame($quote->id, $first->quote_id);
        $this->assertSame($snapshotOne->id, $first->quote_send_snapshot_id);

        $second = $service->sendToCustomer($quote->fresh(), [
            'contact_name' => 'Ayşe Yıldız',
            'contact_email' => 'ayse@example.test',
            'contact_phone' => '05320000001',
            'sent_channel' => QuoteSendSnapshot::CHANNEL_WHATSAPP_LINK,
        ], $this->adminUser);

        $snapshotTwo = $second->sendSnapshot()->firstOrFail();

        $this->assertSame(2, $snapshotTwo->send_no);
        $this->assertSame(2, $quote->quoteSendSnapshots()->count());
        $this->assertSame(2, $quote->quoteApprovalRequests()->count());
        $this->assertSame(QuoteApprovalRequest::STATUS_CANCELLED, $first->fresh()->status);
        $this->assertSame(QuoteApprovalRequest::STATUS_WAITING, $second->fresh()->status);
        $this->assertNotNull($first->fresh()->responded_at);

        $otherQuote = $this->createQuote('TK-OTHER-001');
        $otherRequest = $service->sendToCustomer($otherQuote, [
            'contact_email' => 'other@example.test',
        ], $this->adminUser);
        $this->assertSame(1, $otherRequest->sendSnapshot?->send_no);
    }

    public function test_send_to_customer_requires_customer_and_item_and_disallows_converted_quotes(): void
    {
        $service = app(QuoteApprovalService::class);

        $quoteWithoutCustomer = $this->createQuote('TK-NO-CUSTOMER-1', withCustomer: false);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Müşteri seçilmeden teklif gönderilemez.');
        $service->sendToCustomer($quoteWithoutCustomer, [], $this->adminUser);
    }

    public function test_send_to_customer_requires_items_and_disallows_converted_quotes_in_separate_cases(): void
    {
        $service = app(QuoteApprovalService::class);

        $quoteWithoutItems = $this->createQuote('TK-NO-ITEM-1', withItem: false);
        try {
            $service->sendToCustomer($quoteWithoutItems, [], $this->adminUser);
            $this->fail('Expected missing item exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('En az bir ürün kalemi olmadan teklif gönderilemez.', $exception->getMessage());
        }

        $convertedQuote = $this->createQuote('TK-CONVERTED-1', workflowStatus: 'quote_converted');
        $convertedQuote->status = 'approved';
        $convertedQuote->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Siparişe dönüşen teklif tekrar gönderilemez.');
        $service->sendToCustomer($convertedQuote, [], $this->adminUser);
    }

    public function test_mark_viewed_approve_request_revision_and_reject_update_quote_consistently(): void
    {
        $service = app(QuoteApprovalService::class);

        $viewQuote = $this->createQuote('TK-VIEW-001');
        $viewRequest = $service->sendToCustomer($viewQuote, ['contact_email' => 'view@example.test'], $this->adminUser);
        $viewed = $service->markViewed($viewRequest);
        $this->assertSame(QuoteApprovalRequest::STATUS_VIEWED, $viewed->status);
        $this->assertNotNull($viewed->viewed_at);

        $approvedQuote = $this->createQuote('TK-APP-001');
        $approvedRequest = $service->sendToCustomer($approvedQuote, ['contact_email' => 'app@example.test'], $this->adminUser);
        $approvedRequest = $service->markViewed($approvedRequest);
        $approved = $service->approve($approvedRequest, 'Onaylıyorum');
        $approvedQuote->refresh();

        $this->assertSame(QuoteApprovalRequest::STATUS_APPROVED, $approved->status);
        $this->assertSame('Onaylıyorum', $approved->customer_note);
        $this->assertSame(Order::CUSTOMER_APPROVAL_APPROVED, $approvedQuote->customer_approval_status);
        $this->assertSame('approved', $approvedQuote->status);
        $this->assertNotNull($approvedQuote->approved_at);

        $revisionQuote = $this->createQuote('TK-REV-001');
        $revisionRequest = $service->sendToCustomer($revisionQuote, ['contact_email' => 'rev@example.test'], $this->adminUser);
        try {
            $service->requestRevision($revisionRequest, '');
            $this->fail('Expected empty note exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Revize isteği için not gerekli.', $exception->getMessage());
        }

        $revisioned = $service->requestRevision($revisionRequest, 'Logo rengini değiştirin');
        $revisionQuote->refresh();

        $this->assertSame(QuoteApprovalRequest::STATUS_REVISION_REQUESTED, $revisioned->status);
        $this->assertSame('Logo rengini değiştirin', $revisioned->customer_note);
        $this->assertSame(Order::CUSTOMER_APPROVAL_REVISION_REQUESTED, $revisionQuote->customer_approval_status);
        $this->assertSame('draft', $revisionQuote->status);
        $this->assertNotNull($revisionQuote->revision_requested_at);

        $rejectedQuote = $this->createQuote('TK-REJ-001');
        $rejectedRequest = $service->sendToCustomer($rejectedQuote, ['contact_email' => 'rej@example.test'], $this->adminUser);
        $rejected = $service->reject($rejectedRequest, 'Uygun değil');
        $rejectedQuote->refresh();

        $this->assertSame(QuoteApprovalRequest::STATUS_REJECTED, $rejected->status);
        $this->assertSame(Order::CUSTOMER_APPROVAL_REJECTED, $rejectedQuote->customer_approval_status);
        $this->assertSame('rejected', $rejectedQuote->status);
        $this->assertNotNull($rejectedQuote->rejected_at);
    }

    public function test_expired_or_cancelled_requests_cannot_be_approved(): void
    {
        $service = app(QuoteApprovalService::class);

        $expiredQuote = $this->createQuote('TK-EXP-001');
        $expiredRequest = $service->sendToCustomer($expiredQuote, [
            'contact_email' => 'expired@example.test',
            'expires_at' => now()->subMinute(),
        ], $this->adminUser);

        try {
            $service->approve($expiredRequest, 'Too late');
            $this->fail('Expected expired approval exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Süresi dolan istek yanıtlanamaz.', $exception->getMessage());
            $this->assertSame(QuoteApprovalRequest::STATUS_EXPIRED, $expiredRequest->fresh()->status);
        }

        $cancelledQuote = $this->createQuote('TK-CAN-001');
        $first = $service->sendToCustomer($cancelledQuote, ['contact_email' => 'first@example.test'], $this->adminUser);
        $service->sendToCustomer($cancelledQuote->fresh(), ['contact_email' => 'second@example.test'], $this->adminUser);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('İptal edilen istek yanıtlanamaz.');
        $service->approve($first->fresh(), 'No longer valid');
    }

    public function test_customer_response_is_bound_to_specific_snapshot_even_if_current_quote_changes(): void
    {
        $service = app(QuoteApprovalService::class);
        $quote = $this->createQuote('TK-SNAPSHOT-001');

        $request = $service->sendToCustomer($quote, ['contact_email' => 'snap@example.test'], $this->adminUser);
        $snapshotId = $request->quote_send_snapshot_id;
        $snapshotBefore = $request->sendSnapshot()->firstOrFail();
        $storedTotal = data_get($snapshotBefore->snapshot_json, 'items.0.line_total');

        $item = $quote->items()->firstOrFail();
        $item->update([
            'product_name' => 'Değişen Kalem',
            'line_total' => 9999,
            'price_snapshot' => [
                'product_total' => 9999,
                'price_snapshot_raw' => ['should_not' => 'appear'],
                'group_code' => 'CHANGED-GROUP',
                'vat_breakdown' => [],
            ],
        ]);

        $approved = $service->approve($request->fresh(), 'Onay verildi');
        $approvedQuote = $quote->fresh();
        $snapshotAfter = QuoteSendSnapshot::query()->findOrFail($snapshotId);

        $this->assertSame($snapshotId, $approved->quote_send_snapshot_id);
        $this->assertSame($storedTotal, data_get($snapshotAfter->snapshot_json, 'items.0.line_total'));
        $this->assertNotSame('Değişen Kalem', data_get($snapshotAfter->snapshot_json, 'items.0.product_name'));
        $this->assertSame(Order::CUSTOMER_APPROVAL_APPROVED, $approvedQuote->customer_approval_status);
    }

    public function test_snapshot_excludes_group_code_product_data_hub_raw_fields_and_raw_price_snapshot(): void
    {
        $service = app(QuoteApprovalService::class);
        $quote = $this->createQuote('TK-SAFE-001', customItemPayload: [
            'product_snapshot' => [
                'display_name' => 'Güvenli Ürün',
                'group_code' => 'PDH-GROUP',
                'raw_payload' => ['supplier_field' => 'secret'],
                'source_summary' => ['raw' => 'hidden'],
            ],
            'price_snapshot' => [
                'product_total' => 1200,
                'vat_rate' => 20,
                'group_code' => 'PRICE-GROUP',
                'raw_price_snapshot' => ['api' => 'raw'],
                'pdh_internal' => ['hidden' => true],
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 200, 'scope' => 'product'],
                ],
            ],
        ]);

        $request = $service->sendToCustomer($quote, ['contact_email' => 'safe@example.test'], $this->adminUser);
        $encoded = json_encode($request->sendSnapshot?->snapshot_json, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('group_code', (string) $encoded);
        $this->assertStringNotContainsString('raw_price_snapshot', (string) $encoded);
        $this->assertStringNotContainsString('pdh_internal', (string) $encoded);
        $this->assertStringNotContainsString('raw_payload', (string) $encoded);
        $this->assertStringNotContainsString('source_summary', (string) $encoded);
    }

    public function test_can_convert_to_order_returns_expected_gate_results_and_invoice_status_remains_separate(): void
    {
        $service = app(QuoteApprovalService::class);

        $waitingQuote = $this->createQuote('TK-GATE-001');
        $service->sendToCustomer($waitingQuote, ['contact_email' => 'gate@example.test'], $this->adminUser);
        $blocked = $service->canConvertToOrder($waitingQuote->fresh());

        $this->assertFalse($blocked['allowed']);
        $this->assertSame('Müşteri onayı olmadan siparişe çevrilemez.', $blocked['reason']);

        $approvedQuote = $this->createQuote('TK-GATE-002');
        $approvedRequest = $service->sendToCustomer($approvedQuote, ['contact_email' => 'gate2@example.test'], $this->adminUser);
        $service->approve($approvedRequest, 'Tamam');
        $allowed = $service->canConvertToOrder($approvedQuote->fresh());

        $this->assertTrue($allowed['allowed']);
        $this->assertNotNull($allowed['approved_request_id']);
        $this->assertNotNull($allowed['approved_snapshot_id']);
        $this->assertSame('fatura', $approvedQuote->fresh()->invoice_status);
        $this->assertSame(Order::CUSTOMER_APPROVAL_APPROVED, $approvedQuote->fresh()->customer_approval_status);

        $approvedQuote->update(['workflow_status' => 'quote_converted']);
        $converted = $service->canConvertToOrder($approvedQuote->fresh());
        $this->assertFalse($converted['allowed']);
        $this->assertSame('Teklif zaten siparişe dönüştürüldü.', $converted['reason']);
    }

    public function test_no_tmp_demo_data_is_created_and_real_sources_remain_untouched(): void
    {
        $service = app(QuoteApprovalService::class);
        $quote = $this->createQuote('TK-REAL-001');

        $supplierNamesBefore = Supplier::query()->orderBy('name')->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Akdeniz Promosyon', 'Etkin Promosyon', 'İlpen', 'Yeni Nesil'], $supplierNamesBefore);

        $service->sendToCustomer($quote, ['contact_email' => 'real@example.test'], $this->adminUser);

        $supplierNamesAfter = Supplier::query()->orderBy('name')->pluck('name')->all();
        $this->assertEquals($supplierNamesBefore, $supplierNamesAfter);
        $this->assertSame(0, Supplier::query()->where('name', 'like', 'TMP%')->count());
        $this->assertSame(0, Supplier::query()->where('name', 'like', 'DEMO%')->count());
    }

    private function createQuote(
        ?string $documentNumber = null,
        ?int $customerId = null,
        bool $withItem = true,
        string $workflowStatus = 'quote',
        array $customItemPayload = [],
        bool $withCustomer = true
    ): Order {
        $quote = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => $documentNumber ?: 'TK-2026-' . random_int(1000, 9999),
            'customer_company_id' => $withCustomer ? ($customerId ?? $this->customer->id) : null,
            'status' => 'draft',
            'workflow_status' => $workflowStatus,
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
            'quote_date' => '2026-06-15',
            'valid_until' => '2026-06-22',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'notes' => 'Quote approval core test',
            'currency' => 'TL',
            'subtotal' => 1200,
            'vat_total' => 240,
            'grand_total' => 1440,
            'product_total' => 1200,
            'print_total' => 0,
            'vat_breakdown_json' => [
                ['rate' => 20, 'total' => 240, 'scope' => 'product'],
            ],
            'created_by' => $this->adminUser->id,
        ]);

        if (! $withItem) {
            return $quote;
        }

        $item = OrderItem::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Test Kalem',
            'product_code' => 'TKL-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Test ürün',
            'product_snapshot' => [
                'display_name' => 'Test Kalem',
            ],
            'price_snapshot' => [
                'product_total' => 1200,
                'vat_rate' => 20,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 240, 'scope' => 'product'],
                ],
            ],
            'stock_snapshot' => [
                'visible_stock_quantity' => 500,
            ],
            'catalog_source' => null,
            'list_price' => 12,
            'discount_rate' => 0,
            'unit_price' => 12,
            'line_total' => 1200,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ], $customItemPayload));

        if ((bool) ($customItemPayload['has_print'] ?? false)) {
            OrderItemPrint::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'order_id' => $quote->id,
                'order_item_id' => $item->id,
                'print_type' => 'UV Baskı',
                'print_option' => 'Tek taraf',
                'print_quantity' => 100,
                'print_unit_price' => 2,
                'print_total' => 200,
                'note' => 'Test baskı',
                'status' => 'draft',
            ]);
        }

        return $quote->fresh();
    }
}
