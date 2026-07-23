<?php

namespace Tests\Feature;

use App\Services\OrderListSummaryService;
use App\Services\OrderShowSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class OrderWorkflowLabelParityTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_order_list_and_order_detail_share_canonical_workflow_labels(): void
    {
        $order = $this->convertQuote($this->createQuoteViaHttp(['document_number' => 'TK-B1-LABEL-001', 'with_print' => true]))->fresh();

        $listRow = app(OrderListSummaryService::class)->buildRow($order->fresh(['customer', 'workForms', 'procurements', 'printProductions', 'deliveries', 'payments']), true);
        $detail = app(OrderShowSummaryService::class)->build($order->fresh(['customer', 'workForms.activityLogs.creator', 'procurements', 'printProductions', 'deliveries', 'payments', 'items.prints.production', 'items.procurement', 'items.workForm', 'items.delivery']), true);

        $this->assertSame($listRow['operation_status_label'], data_get($detail, 'overview.operation_status_label'));
        $this->assertSame($listRow['next_action_label'], data_get($detail, 'overview.next_action_label'));
        $this->assertSame($listRow['workflow_focus_key'], data_get($detail, 'overview.workflow_focus_key'));
    }
}
