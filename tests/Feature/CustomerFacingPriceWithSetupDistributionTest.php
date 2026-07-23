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

class CustomerFacingPriceWithSetupDistributionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_public_customer_price_keeps_setup_distributed_print_price_separate_when_breakdown_is_visible(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $customer = Company::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => 'quote_customer_approval',
                'feature_key' => 'public_quote_approval',
            ],
            ['is_enabled' => true]
        );

        $quote = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-SETUP-PUBLIC-001',
            'customer_company_id' => $customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_NOT_SENT,
            'quote_date' => '2026-07-02',
            'valid_until' => '2026-07-09',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 2300,
            'vat_total' => 460,
            'grand_total' => 2760,
            'product_total' => 500,
            'print_total' => 1800,
            'show_print_price_details_to_customer' => true,
            'created_by' => $adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Setup Dağıtımlı Ürün',
            'product_code' => 'SETUP-CUST-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'unit_price' => 5,
            'line_total' => 500,
            'has_print' => true,
            'print_total' => 1800,
            'status' => 'pending',
            'price_snapshot' => [
                'product_total' => 500,
                'vat_rate' => 20,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 100, 'scope' => 'product'],
                    ['rate' => 20, 'total' => 360, 'scope' => 'print'],
                ],
            ],
        ]);

        $item->prints()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $quote->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Klişeli Baskı',
            'cliche_status' => 'Yeni üretilecek',
            'setup_pricing_enabled' => true,
            'setup_type' => 'cliche',
            'setup_status' => 'Yeni üretilecek',
            'setup_total_amount' => 800,
            'setup_distribution_quantity' => 100,
            'setup_unit_amount' => 8,
            'base_print_unit_price' => 10,
            'print_quantity' => 100,
            'print_unit_price' => 18,
            'print_total' => 1800,
            'note' => 'deneme baskı',
            'status' => 'draft',
        ]);

        $request = app(QuoteApprovalService::class)->sendToCustomer($quote, [
            'contact_email' => 'setup-approval@example.test',
        ], $adminUser);

        $this->get(route('public.quotes.approval.show', ['token' => $request->token]))
            ->assertOk()
            ->assertSee('5,00 TL')
            ->assertSee('500,00 TL')
            ->assertSee('Ürün + Baskı Toplamı')
            ->assertSee('2.300,00 TL')
            ->assertSee('Baskı Birim Fiyatı: 18,00 TL')
            ->assertSee('1.800,00 TL')
            ->assertDontSee('Ara eleman toplam tutarı')
            ->assertDontSee('Ara eleman toplamı')
            ->assertDontSee('Klişe maliyeti')
            ->assertDontSee('setup_total_amount')
            ->assertDontSee('base_print_unit_price');
    }
}
