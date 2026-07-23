<?php

namespace Tests\Feature;

use App\Services\OrderShowSummaryService;
use App\Services\ProcessDepth\TenantProcessDepthResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class OrderProcessDepthAdmissionTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_conversion_uses_current_effective_process_depth_from_canonical_resolver(): void
    {
        $this->setTenantProcessDepth('controlled');
        $order = $this->convertQuote($this->createQuoteViaHttp(['document_number' => 'TK-B1-DEPTH-001', 'with_print' => true]))->fresh(['tenant', 'workForms.activityLogs.creator', 'procurements', 'printProductions', 'deliveries', 'payments', 'items.prints.production', 'items.procurement', 'items.workForm', 'items.delivery']);

        $resolved = app(TenantProcessDepthResolver::class)->resolve($this->tenant->fresh());
        $screen = app(OrderShowSummaryService::class)->build($order, true);

        $this->assertSame($resolved['key'], data_get($screen, 'overview.process_depth.key'));
        $this->assertSame($resolved['label'], data_get($screen, 'overview.process_depth.label'));
        $this->assertNotEmpty(data_get($screen, 'overview.process_depth.focus.primary_label'));
    }
}
