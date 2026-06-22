<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteToOrderConversionTest extends TestCase
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

    public function test_real_promotion_quote_payload_can_be_converted_to_order_with_snapshot_preserved(): void
    {
        $quote = $this->createQuoteViaHttp('fatura');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}");

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $response->assertRedirect(route('admin.orders.show', $order));

        $order->load('items.prints');
        $item = $order->items->firstOrFail();

        $this->assertMatchesRegularExpression('/^SP-\d{4}-\d{4}$/', $order->document_number);
        $this->assertSame($quote->id, $order->source_quote_id);
        $this->assertSame($quote->document_number, $order->source_quote_number);
        $this->assertSame('order', $order->document_type);
        $this->assertSame($quote->customer_company_id, $order->customer_company_id);
        $this->assertSame($quote->currency, $order->currency);
        $this->assertSame($quote->invoice_status, $order->invoice_status);
        $this->assertSame($quote->delivery_type, $order->delivery_type);
        $this->assertSame('pending', $order->status);
        $this->assertSame('order_created', $order->workflow_status);
        $this->assertEquals(1970.0, (float) $order->subtotal);
        $this->assertEquals(347.0, (float) $order->vat_total);
        $this->assertEquals(2317.0, (float) $order->grand_total);
        $this->assertEquals(470.0, (float) $order->product_total);
        $this->assertEquals(1500.0, (float) $order->print_total);
        $this->assertEqualsCanonicalizing([
            ['rate' => 10.0, 'total' => 47.0, 'scope' => 'product'],
            ['rate' => 20.0, 'total' => 300.0, 'scope' => 'print'],
        ], array_map(static fn (array $slice) => [
            'rate' => (float) $slice['rate'],
            'total' => (float) $slice['total'],
            'scope' => $slice['scope'],
        ], $order->vat_breakdown_json ?? []));

        $this->assertCount(1, $order->items);
        $this->assertEquals(470.0, (float) $item->line_total);
        $this->assertEquals(1500.0, (float) $item->print_total);
        $this->assertTrue((bool) data_get($item->price_snapshot, 'manual_unit_price'));
        $this->assertEquals(470.0, (float) data_get($item->price_snapshot, 'product_total'));
        $this->assertEquals(1500.0, (float) data_get($item->price_snapshot, 'print_total'));
        $this->assertCount(2, $item->prints);
        $this->assertEquals(1500.0, (float) $item->prints->sum('print_total'));
        $this->assertSame($quote->id, $quote->fresh()->id);
        $this->assertSame('quote', $quote->fresh()->document_type);
        $this->assertSame('quote_converted', $quote->fresh()->workflow_status);
        $this->assertFalse($quote->fresh()->canBeEdited());
    }

    public function test_fis_quote_conversion_preserves_zero_vat_and_totals(): void
    {
        $quote = $this->createQuoteViaHttp('fis');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $order->load('items.prints');
        $item = $order->items->firstOrFail();

        $this->assertSame('fis', $order->invoice_status);
        $this->assertEquals(1970.0, (float) $order->subtotal);
        $this->assertEquals(0.0, (float) $order->vat_total);
        $this->assertEquals(1970.0, (float) $order->grand_total);
        $this->assertEquals([], $order->vat_breakdown_json ?? []);
        $this->assertTrue((bool) data_get($item->price_snapshot, 'manual_unit_price'));
        $this->assertEquals(0.0, (float) data_get($item->price_snapshot, 'vat_rate'));
        $this->assertEquals(0.0, (float) data_get($item->price_snapshot, 'print_vat_rate'));
        $this->assertEquals([], data_get($item->price_snapshot, 'vat_breakdown', []));
    }

    public function test_same_quote_cannot_be_converted_twice(): void
    {
        $quote = $this->createQuoteViaHttp('fatura');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}")
            ->assertRedirect();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$quote->id}");

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->firstOrFail();

        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHas('info', 'Bu teklif daha önce siparişe dönüştürüldü.');
        $this->assertSame(1, Order::query()->where('document_type', 'order')->where('source_quote_id', $quote->id)->count());
    }

    public function test_non_quote_record_cannot_be_converted(): void
    {
        $quote = $this->createQuoteViaHttp('fatura');

        $order = Order::query()->create([
            'tenant_account_id' => $quote->tenant_account_id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-2026-9999',
            'customer_company_id' => $quote->customer_company_id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'subtotal' => 10,
            'vat_total' => 0,
            'grand_total' => 10,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post("/admin/orders/convert/{$order->id}");

        $response->assertRedirect(route('admin.orders.index'));
        $response->assertSessionHasErrors(['error' => 'Bu kayıt teklif değil.']);
    }

    public function test_conversion_rolls_back_when_item_copy_fails(): void
    {
        $quote = $this->createQuoteViaHttp('fatura');

        OrderItem::creating(static function (): void {
            throw new \RuntimeException('Simulated item copy failure.');
        });

        try {
            $response = $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->post("/admin/orders/convert/{$quote->id}");

            $response->assertRedirect(route('admin.promotion-quotes.show', $quote));
            $response->assertSessionHasErrors(['error' => 'Siparise donusturme sirasinda hata olustu.']);
            $this->assertSame(0, Order::query()->where('document_type', 'order')->where('source_quote_id', $quote->id)->count());
            $this->assertSame('quote', $quote->fresh()->document_type);
        } finally {
            OrderItem::flushEventListeners();
            OrderItem::clearBootedModels();
        }
    }

    private function createQuoteViaHttp(string $invoiceStatus): Order
    {
        $customer = Company::query()->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $partner = Company::query()
            ->where('status', 'active')
            ->whereKeyNot($customer->id)
            ->orderBy('id')
            ->firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post('/admin/promotion-quotes', [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-06-12',
                'valid_until' => '2026-06-19',
                'invoice_status' => $invoiceStatus,
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'notes' => 'Conversion smoke payload',
                'items' => [[
                    'product_name' => 'Smoke Test Kalem',
                    'product_code' => 'SMOKE-001',
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
