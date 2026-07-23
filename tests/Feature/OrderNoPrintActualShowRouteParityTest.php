<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class OrderNoPrintActualShowRouteParityTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_order_show_route_renders_required_degil_for_no_print_order(): void
    {
        $order = $this->convertQuote($this->createQuoteViaHttp([
            'document_number' => 'TK-NOPRINT-HTTP-001',
            'with_print' => false,
        ]))->fresh(['procurements.supplierRequestItems.request', 'workForms.attachments', 'workForms.activityLogs.creator', 'items.prints']);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->centralHost])
            ->get(route('admin.orders.show', $order));

        $response->assertOk();
        $response->assertSee('Sipariş Akışı');
        $response->assertSee('Aktif Odak');
        $response->assertSee('Süreç Durumu');
        $response->assertSee('Gerekli Değil');
        $response->assertDontSee('Grafik hazır');
        $response->assertDontSee('Grafik Hazır');
        $response->assertSee('Talep Hazırlanacak');
        $response->assertSee('Tedarik talebini hazırla');
    }
}
