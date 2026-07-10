<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkFormDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteOrderListFixtures;
use Tests\TestCase;

class QuoteOrderListTurkishTerminologyTest extends TestCase
{
    use BuildsQuoteOrderListFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuoteOrderListFixtures();
    }

    public function test_lists_use_turkish_terminology_without_broken_labels(): void
    {
        $this->createQuote(['document_number' => 'TK-TR-001']);
        $this->createConvertedQuote(['document_number' => 'TK-TR-002']);
        $this->createOrder(['document_number' => 'SP-TR-001'], [
            'graphic_status' => 'uretime_hazir',
            'procurement_status' => OrderItemProcurement::STATUS_FULLY_RECEIVED,
            'production_status' => OrderItemPrintProduction::STATUS_COMPLETED,
            'delivery_status' => OrderItemWorkFormDelivery::STATUS_DELIVERED,
        ]);

        $quoteResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.index', ['view' => 'converted']));

        $orderResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index', ['status' => 'completed']));

        $quoteResponse->assertOk()
            ->assertSee('Teklifler', false)
            ->assertSee('Siparişe Dönüşenler')
            ->assertSee('Açık Teklifler')
            ->assertDontSee('Siparis')
            ->assertDontSee('Donusen')
            ->assertDontSee('Arsiv');

        $orderResponse->assertOk()
            ->assertSee('Aktif Siparişler')
            ->assertSee('Tamamlanan Siparişler')
            ->assertSee('Sıradaki İş')
            ->assertDontSee('Tamamlanmis')
            ->assertDontSee('Guncel');
    }
}
