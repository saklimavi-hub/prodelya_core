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

class PublicQuoteApprovalCustomerPriceDisplayTest extends TestCase
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

    public function test_public_approval_uses_customer_facing_print_included_price_and_optional_breakdown_visibility(): void
    {
        $visibleQuote = $this->createQuote('TK-PUBLIC-PRICE-01', true);
        $visibleRequest = app(QuoteApprovalService::class)->sendToCustomer($visibleQuote, [
            'contact_email' => 'visible@example.test',
        ], $this->adminUser);

        $this->get(route('public.quotes.approval.show', ['token' => $visibleRequest->token]))
            ->assertOk()
            ->assertSee('15,00 TL')
            ->assertSee('1.500,00 TL')
            ->assertSee('Baskı Birim: 10,00 TL')
            ->assertSee('Baskı Toplamı')
            ->assertSee('1.000,00 TL')
            ->assertDontSee('supplier_cost')
            ->assertDontSee('group_code');

        $hiddenQuote = $this->createQuote('TK-PUBLIC-PRICE-02', false);
        $hiddenRequest = app(QuoteApprovalService::class)->sendToCustomer($hiddenQuote, [
            'contact_email' => 'hidden@example.test',
        ], $this->adminUser);

        $this->get(route('public.quotes.approval.show', ['token' => $hiddenRequest->token]))
            ->assertOk()
            ->assertSee('15,00 TL')
            ->assertSee('1.500,00 TL')
            ->assertDontSee('Baskı Birim:')
            ->assertSee('Baskı Toplamı')
            ->assertSee('Fiyata dahil')
            ->assertDontSee('supplier_cost')
            ->assertDontSee('group_code');
    }

    private function createQuote(string $documentNumber, bool $showPrintPriceDetails): Order
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
            'subtotal' => 1500,
            'vat_total' => 300,
            'grand_total' => 1800,
            'product_total' => 500,
            'print_total' => 1000,
            'show_print_price_details_to_customer' => $showPrintPriceDetails,
            'created_by' => $this->adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Public Approval Fiyat Ürünü',
            'product_code' => 'PAF-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'description' => 'Müşteriye görünür ürün',
            'unit_price' => 5,
            'line_total' => 500,
            'has_print' => true,
            'print_total' => 1000,
            'status' => 'pending',
            'price_snapshot' => [
                'product_total' => 500,
                'vat_rate' => 20,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 100, 'scope' => 'product'],
                    ['rate' => 20, 'total' => 200, 'scope' => 'print'],
                ],
                'supplier_cost' => 99,
                'group_code' => 'SECRET-GROUP',
            ],
        ]);

        $item->prints()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Çift Taraf Baskı',
            'print_quantity' => 100,
            'print_unit_price' => 10,
            'print_total' => 1000,
            'note' => 'BASKI ADI: 55555555',
            'status' => 'draft',
        ]);

        return $quote->fresh();
    }
}
