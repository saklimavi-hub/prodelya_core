<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteToOrderPrintSetupPricingCarryoverTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_quote_to_order_preserves_setup_pricing_fields(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-07-02',
                'valid_until' => '2026-07-09',
                'invoice_status' => 'fis',
                'currency' => 'TL',
                'items' => [[
                    'product_name' => 'Carryover Ürünü',
                    'product_code' => 'CARRY-001',
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'unit_price' => '5',
                    'list_price' => '5',
                    'discount_rate' => '0',
                    'manual_unit_price' => '1',
                    'vat_rate' => '0',
                    'has_print' => '1',
                    'prints' => [[
                        'print_type' => 'Sıcak Baskı',
                        'print_option' => 'Klişeli sıcak baskı',
                        'print_quantity' => '100',
                        'base_print_unit_price' => '10',
                        'print_unit_price' => '18',
                        'cliche_status' => 'Yeni üretilecek',
                        'setup_status' => 'Yeni üretilecek',
                        'setup_pricing_enabled' => '1',
                        'setup_type' => 'cliche',
                        'setup_total_amount' => '800',
                        'note' => 'carryover',
                    ]],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();

        $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $print = $order->items()->firstOrFail()->prints()->firstOrFail();

        $this->assertTrue((bool) $print->setup_pricing_enabled);
        $this->assertSame('cliche', $print->setup_type);
        $this->assertSame('Yeni üretilecek', $print->setup_status);
        $this->assertEquals(800.0, (float) $print->setup_total_amount);
        $this->assertEquals(100.0, (float) $print->setup_distribution_quantity);
        $this->assertEquals(8.0, (float) $print->setup_unit_amount);
        $this->assertEquals(10.0, (float) $print->base_print_unit_price);
        $this->assertEquals(18.0, (float) $print->print_unit_price);
        $this->assertEquals(1800.0, (float) $print->print_total);
    }
}
