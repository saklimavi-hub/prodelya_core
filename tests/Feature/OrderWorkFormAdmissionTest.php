<?php

namespace Tests\Feature;

use App\Models\OrderItemWorkForm;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class OrderWorkFormAdmissionTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_order_conversion_creates_exactly_one_active_work_form_and_service_retry_keeps_it_single(): void
    {
        $order = $this->convertQuote($this->createQuoteViaHttp(['document_number' => 'TK-B1-WF-001', 'with_print' => true]));
        $service = app(WorkFormCreationService::class);

        $service->createForOrder($order->fresh(['items.prints']), $this->adminUser);
        $service->createForOrder($order->fresh(['items.prints']), $this->adminUser);

        $forms = OrderItemWorkForm::query()->where('order_id', $order->id)->get();
        $this->assertCount(1, $forms);
        $this->assertSame($order->source_quote_id, $forms->first()->source_quote_id);
        $this->assertNotEmpty($forms->first()->product_snapshot);
        $this->assertNotEmpty($forms->first()->print_snapshot);
    }
}
