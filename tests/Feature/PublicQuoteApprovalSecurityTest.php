<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\QuoteApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicQuoteApprovalSecurityTest extends TestCase
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

    public function test_public_show_uses_snapshot_projection_and_hides_live_and_forbidden_fields(): void
    {
        $quote = $this->createQuote('TK-PUBLIC-SEC-001');
        $service = app(QuoteApprovalService::class);
        $approvalRequest = $service->sendToCustomer($quote, [
            'contact_email' => 'secure@example.test',
        ], $this->adminUser);

        $item = $quote->items()->firstOrFail();
        $item->forceFill([
            'product_name' => 'CANLI VERI DEGISMIS',
            'product_code' => 'LIVE-CHANGED',
            'description' => 'internal note live data',
            'product_snapshot' => [
                'group_code' => 'PDH-HIDDEN',
                'pdh_raw' => ['secret' => 'hidden'],
                'file_path' => 'C:\\private\\quote.pdf',
            ],
            'price_snapshot' => [
                'purchase_total' => 9999,
                'subcontractor_cost' => 500,
                'setup_cost' => 100,
                'balance_due' => 2500,
                'notification_logs' => ['id' => 1],
            ],
            'line_total' => 9999,
        ])->save();

        $quote->forceFill([
            'subtotal' => 9999,
            'vat_total' => 1999,
            'grand_total' => 11998,
            'notes' => 'internal note hidden',
        ])->save();

        $response = $this->get(route('public.quotes.approval.show', ['token' => $approvalRequest->token]));

        $response->assertOk();
        $response->assertSee('Public Quote Güvenlik Ürünü');
        $response->assertDontSee('CANLI VERI DEGISMIS');
        $response->assertSee('1.200,00 TL');
        $response->assertSee('240,00 TL');
        $response->assertSee('1.440,00 TL');
        $response->assertDontSee('11.998,00 TL');
        $response->assertDontSee('purchase_total', false);
        $response->assertDontSee('subcontractor_cost', false);
        $response->assertDontSee('setup_cost', false);
        $response->assertDontSee('balance_due', false);
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('pdh_raw', false);
        $response->assertDontSee('file_path', false);
        $response->assertDontSee('physical_path', false);
        $response->assertDontSee('internal note', false);
        $response->assertDontSee('notification_logs', false);
        $response->assertDontSee('name="token"', false);
        $response->assertDontSee('Ham token');
    }

    public function test_public_quote_approval_requires_no_auth_and_does_not_break_public_tracking(): void
    {
        $quote = $this->createQuote('TK-PUBLIC-SEC-002');
        $approvalRequest = app(QuoteApprovalService::class)->sendToCustomer($quote, [
            'contact_email' => 'public@example.test',
        ], $this->adminUser);

        $this->get(route('public.quotes.approval.show', ['token' => $approvalRequest->token]))
            ->assertOk()
            ->assertSee($quote->document_number);

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
            'notes' => 'Customer safe teklif notu',
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
            'product_name' => 'Public Quote Güvenlik Ürünü',
            'product_code' => 'PQS-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Müşteri için güvenli açıklama',
            'product_snapshot' => ['display_name' => 'Public Quote Güvenlik Ürünü'],
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
