<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use App\Services\OrderCurrentAccountDebitSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteToOrderCreatesCustomerDebitTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_quote_to_order_conversion_creates_customer_debit_with_order_total(): void
    {
        $adminUser = \App\Models\User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $quote = $this->createQuoteViaHttp('fatura', $adminUser);

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
            ->where('source_type', OrderCurrentAccountDebitSyncService::SOURCE_TYPE)
            ->where('source_id', $order->id)
            ->where('transaction_type', CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT)
            ->firstOrFail();

        $this->assertSame($quote->customer_company_id, $order->customer_company_id);
        $this->assertSame((string) $order->grand_total, (string) $transaction->amount);
        $this->assertSame('2317.00', (string) $transaction->amount);
        $this->assertSame('Siparişten oluşan müşteri borcu', $transaction->description);
        $this->assertSame($order->document_number, data_get($transaction->meta_json, 'auto_sync.order_number'));
        $this->assertTrue($quote->shouldShowPrintPriceDetailsToCustomer());
    }

    private function createQuoteViaHttp(string $invoiceStatus, \App\Models\User $adminUser): Order
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $partner = Company::query()
            ->where('status', 'active')
            ->whereKeyNot($customer->id)
            ->orderBy('id')
            ->firstOrFail();

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-06-12',
                'valid_until' => '2026-06-19',
                'invoice_status' => $invoiceStatus,
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Order receivable sync quote payload',
                'items' => [[
                    'product_name' => 'Sync Test Kalem',
                    'product_code' => 'SYNC-001',
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
                            'note' => 'test baskı',
                        ],
                        [
                            'print_type' => 'Lazer',
                            'print_option' => 'Tek taraf lazer',
                            'production_type' => 'Dış üretim / Fason',
                            'subcontractor_company_id' => $partner->id,
                            'print_quantity' => '100',
                            'print_unit_price' => '10',
                            'note' => 'test ikinci baskı',
                        ],
                    ],
                ]],
            ]);

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();
        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));

        return $quote;
    }
}
