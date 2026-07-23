<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemWorkForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoPrintOrderSkipsGraphicProductionTest extends TestCase
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

    public function test_no_print_order_skips_graphic_and_production_lists_and_summary_labels(): void
    {
        $quote = $this->createQuoteViaHttp(withPrint: false);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        $order = Order::query()
            ->orders()
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $workForm = OrderItemWorkForm::query()
            ->where('order_id', $order->id)
            ->with(['orderItem', 'procurement', 'delivery', 'printProductions'])
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(0, OrderItemPrint::query()->where('order_id', $order->id)->count());
        $this->assertSame(0, OrderItemPrintProduction::query()->where('order_id', $order->id)->count());
        $this->assertNotNull($workForm);
        $this->assertSame('gerekli_degil', data_get($workForm->graphic_snapshot, 'status'));
        $this->assertSame('gerekli_degil', data_get($workForm->production_snapshot, 'status'));

        $graphicsIndex = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index'));

        $graphicsIndex->assertOk();
        $graphicsIndex->assertDontSee($order->document_number);
        $graphicsIndex->assertDontSee($workForm->work_form_number);
        $graphicsIndex->assertDontSee((string) data_get($workForm->product_snapshot, 'product_name'));
        $graphicsIndex->assertDontSee('group_code', false);

        $productionsIndex = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index'));

        $productionsIndex->assertOk();
        $productionsIndex->assertDontSee($order->document_number);
        $productionsIndex->assertDontSee($workForm->work_form_number);
        $productionsIndex->assertDontSee((string) data_get($workForm->product_snapshot, 'product_name'));

        $ordersIndex = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index'));

        $ordersIndex->assertOk();
        $ordersIndex->assertSee($order->document_number);
        $ordersIndex->assertDontSee('Grafik Bekliyor');
        $ordersIndex->assertDontSee('Üretim Bekliyor');
        $ordersIndex->assertSeeTextInOrder([$order->document_number, 'Talep Hazırlanacak', 'Tedarik talebini hazırla']);
        $ordersIndex->assertDontSee('group_code', false);
    }

    private function createQuoteViaHttp(bool $withPrint): Order
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-06-15',
                'valid_until' => '2026-06-22',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => $withPrint ? 'Printed payload' : 'No-print payload',
                'items' => [[
                    'product_name' => $withPrint ? 'Baskılı Test Ürünü' : 'Baskısız Test Ürünü',
                    'product_code' => $withPrint ? 'NOPRINT-CHECK-PRINT' : 'NOPRINT-CHECK-001',
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '25',
                    'discount_rate' => '0',
                    'unit_price' => '25',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => $withPrint ? '1' : '0',
                    'prints' => $withPrint ? [[
                        'print_type' => 'UV Baskı',
                        'print_option' => 'Tek taraf',
                        'production_type' => 'İç üretim',
                        'print_quantity' => '100',
                        'print_unit_price' => '4',
                    ]] : [],
                ]],
            ]);

        $quote = Order::query()->quotes()->latest('id')->firstOrFail();
        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));

        return $quote;
    }
}
