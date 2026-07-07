<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteToOrderPrintPriceVisibilityCarryoverTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_quote_to_order_carries_customer_print_price_visibility_flag(): void
    {
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $quote = $this->createQuote($customer, false);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote));

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $response->assertRedirect(route('admin.orders.show', $order));
        $this->assertFalse($quote->fresh()->shouldShowPrintPriceDetailsToCustomer());
        $this->assertFalse($order->fresh()->shouldShowPrintPriceDetailsToCustomer());
    }

    private function createQuote(Company $customer, bool $showPrintPriceDetails): Order
    {
        $quote = Order::query()->create([
            'tenant_account_id' => $customer->tenant_account_id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-PRINT-VIS-' . random_int(1000, 9999),
            'customer_company_id' => $customer->id,
            'status' => 'approved',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_APPROVED,
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
            'tenant_account_id' => $quote->tenant_account_id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Carryover Ürünü',
            'product_code' => 'CO-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'unit_price' => 5,
            'line_total' => 500,
            'has_print' => true,
            'print_total' => 1000,
            'status' => 'pending',
            'price_snapshot' => [
                'product_total' => 500,
                'print_total' => 1000,
                'vat_rate' => 20,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 100, 'scope' => 'product'],
                    ['rate' => 20, 'total' => 200, 'scope' => 'print'],
                ],
            ],
        ]);

        $item->prints()->create([
            'tenant_account_id' => $quote->tenant_account_id,
            'order_id' => $quote->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_quantity' => 100,
            'print_unit_price' => 10,
            'print_total' => 1000,
            'status' => 'draft',
        ]);

        return $quote;
    }
}
