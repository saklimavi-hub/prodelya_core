<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteEditCustomerUxTest extends TestCase
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

    public function test_edit_screen_keeps_existing_customer_selected_and_search_ui_visible(): void
    {
        $customer = Company::query()
            ->where('tenant_account_id', 1)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        $quote = Order::query()->create([
            'tenant_account_id' => 1,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-2026-4701',
            'customer_company_id' => $customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => now()->toDateString(),
            'invoice_status' => 'fis',
            'currency' => 'TL',
            'subtotal' => 0,
            'vat_total' => 0,
            'grand_total' => 0,
            'product_total' => 0,
            'print_total' => 0,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.edit', $quote));

        $response->assertOk();
        $response->assertSee('Müşteri Ara');
        $response->assertSee('selectedCustomer', false);
        $response->assertSee($customer->legal_name);
        $response->assertSee('id="quote-customer-selected-card"', false);
        $response->assertSee('Seçili Müşteri');
        $response->assertSee('Değiştir');
        $response->assertSee('Cari durumu');
    }
}
