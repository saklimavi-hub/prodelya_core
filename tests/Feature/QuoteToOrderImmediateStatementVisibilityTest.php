<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteToOrderImmediateStatementVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_quote_to_order_immediately_shows_customer_debit_on_statement_surfaces(): void
    {
        $adminUser = \App\Models\User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $quote = $this->createQuoteViaHttp($adminUser, '2026-06-12');

        $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/orders/convert/' . $quote->id)
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $transaction = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $order->tenant_account_id)
            ->where('source_type', 'order')
            ->where('source_id', $order->id)
            ->where('transaction_type', CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT)
            ->firstOrFail();

        $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/companies/' . $order->customer_company_id . '?tab=ekstre')
            ->assertOk()
            ->assertSee('Ekstre ve Ön Muhasebe')
            ->assertSee('Siparişten oluşan müşteri borcu')
            ->assertSee($order->document_number)
            ->assertSee($transaction->formattedAmount());

        $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/current-accounts/' . $transaction->current_account_id . '/transactions')
            ->assertOk()
            ->assertSee('Siparişten oluşan müşteri borcu')
            ->assertSee($order->document_number)
            ->assertSee('Borç')
            ->assertSee('Alacak')
            ->assertSee('Bakiye');

        $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get('/admin/companies')
            ->assertOk()
            ->assertSee($quote->customer->legal_name)
            ->assertSee('2.317,00 TL')
            ->assertDontSee('+2.317,00 TL');
    }

    private function createQuoteViaHttp(\App\Models\User $adminUser, string $quoteDate): Order
    {
        $customer = \App\Models\Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $partner = \App\Models\Company::query()
            ->where('status', 'active')
            ->whereKeyNot($customer->id)
            ->orderBy('id')
            ->firstOrFail();

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => $quoteDate,
                'valid_until' => '2026-07-19',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Immediate statement visibility quote payload',
                'items' => [[
                    'product_name' => 'Immediate Visibility Kalem',
                    'product_code' => 'VIS-001',
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '8.60',
                    'discount_rate' => '45',
                    'unit_price' => '4.70',
                    'manual_unit_price' => '1',
                    'vat_rate' => '10',
                    'has_print' => '1',
                    'prints' => [
                        [
                            'print_type' => 'UV Baskı',
                            'print_option' => 'Tek taraf baskılı',
                            'production_type' => 'İç üretim',
                            'print_quantity' => '100',
                            'print_unit_price' => '5',
                        ],
                        [
                            'print_type' => 'Lazer',
                            'print_option' => 'Tek taraf lazer',
                            'production_type' => 'Dış üretim / Fason',
                            'subcontractor_company_id' => $partner->id,
                            'print_quantity' => '100',
                            'print_unit_price' => '10',
                        ],
                    ],
                ]],
            ]);

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();
        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));

        return $quote->fresh(['customer']);
    }
}
