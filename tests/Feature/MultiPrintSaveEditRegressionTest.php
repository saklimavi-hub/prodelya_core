<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiPrintSaveEditRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_four_print_rows_are_persisted_rendered_on_edit_and_carried_to_order(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-07-02',
                'valid_until' => '2026-07-09',
                'invoice_status' => 'fis',
                'currency' => 'TL',
                'items' => [[
                    'product_name' => 'Çoklu Baskı Test Ürünü',
                    'product_code' => 'MULTI-PRINT-001',
                    'quantity' => '10',
                    'unit' => 'Adet',
                    'unit_price' => '5',
                    'list_price' => '5',
                    'discount_rate' => '0',
                    'manual_unit_price' => '1',
                    'vat_rate' => '0',
                    'has_print' => '1',
                    'prints' => [
                        [
                            'print_type' => 'UV Baskı',
                            'print_option' => 'Tek taraf UV baskı',
                            'print_quantity' => '10',
                            'print_unit_price' => '1',
                            'print_total' => '10',
                            'note' => 'print-row-1a',
                        ],
                        [
                            'print_type' => 'Lazer',
                            'print_option' => 'Tek pozisyon lazer',
                            'print_quantity' => '10',
                            'print_unit_price' => '2',
                            'print_total' => '20',
                            'note' => 'print-row-1b',
                        ],
                        [
                            'print_type' => 'Sıcak Baskı',
                            'print_option' => 'Klişeli sıcak baskı',
                            'print_quantity' => '10',
                            'base_print_unit_price' => '25',
                            'print_unit_price' => '125',
                            'print_total' => '1250',
                            'cliche_status' => 'Yeni üretilecek',
                            'setup_status' => 'Yeni üretilecek',
                            'setup_pricing_enabled' => '1',
                            'setup_type' => 'cliche',
                            'setup_total_amount' => '1000',
                            'setup_distribution_quantity' => '10',
                            'setup_unit_amount' => '100',
                            'note' => 'print-row-1c',
                        ],
                        [
                            'print_type' => 'Serigrafi',
                            'print_option' => 'Tek renk serigrafi',
                            'print_quantity' => '10',
                            'base_print_unit_price' => '15',
                            'print_unit_price' => '55',
                            'print_total' => '550',
                            'cliche_status' => 'Yeni üretilecek',
                            'setup_status' => 'Yeni üretilecek',
                            'setup_pricing_enabled' => '1',
                            'setup_type' => 'film',
                            'setup_total_amount' => '400',
                            'setup_distribution_quantity' => '10',
                            'setup_unit_amount' => '40',
                            'note' => 'print-row-1d',
                        ],
                    ],
                ]],
            ]);

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();
        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));

        $item = $quote->items()->with('prints')->firstOrFail();
        $this->assertCount(4, $item->prints);
        $this->assertSame(
            ['print-row-1a', 'print-row-1b', 'print-row-1c', 'print-row-1d'],
            $item->prints->pluck('note')->values()->all()
        );

        $edit = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.edit', $quote));

        $edit->assertOk()
            ->assertSee('print-row-1a')
            ->assertSee('print-row-1b')
            ->assertSee('print-row-1c')
            ->assertSee('print-row-1d');

        $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $orderItem = $order->items()->with('prints')->firstOrFail();

        $this->assertCount(4, $orderItem->prints);
        $this->assertSame(
            ['print-row-1a', 'print-row-1b', 'print-row-1c', 'print-row-1d'],
            $orderItem->prints->pluck('note')->values()->all()
        );
    }
}
