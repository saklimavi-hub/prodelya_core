<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use App\Models\OrderItemWorkForm;
use App\Services\OrderCurrentAccountDebitSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class QuoteToOrderConversionIdempotencyTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_repeat_conversion_returns_existing_order_without_duplicate_side_effects(): void
    {
        $quote = $this->createQuoteViaHttp([
            'document_number' => 'TK-B1-IDEMP-001',
            'with_print' => true,
        ]);

        $firstResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->centralHost])
            ->post(route('admin.orders.convert.from.quote', $quote));

        $order = Order::query()->orders()->where('source_quote_id', $quote->id)->latest('id')->firstOrFail();
        $firstResponse->assertRedirect(route('admin.orders.show', $order));

        $secondResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->centralHost])
            ->post(route('admin.orders.convert.from.quote', $quote));

        $secondResponse->assertRedirect(route('admin.orders.show', $order));
        $secondResponse->assertSessionHas('info', 'Bu teklif daha önce siparişe dönüştürüldü.');

        $this->assertSame(1, Order::query()->orders()->where('source_quote_id', $quote->id)->count());
        $this->assertSame(1, OrderItemWorkForm::query()->where('source_quote_id', $quote->id)->count());
        $this->assertSame(1, CurrentAccountTransaction::query()
            ->where('tenant_account_id', $order->tenant_account_id)
            ->where('source_type', OrderCurrentAccountDebitSyncService::SOURCE_TYPE)
            ->where('source_id', $order->id)
            ->where('transaction_type', CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT)
            ->count());
    }
}
