<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionDraftFixtures;
use Tests\TestCase;

class RepeatOrderCreatesNewQuoteDraftTest extends TestCase
{
    use BuildsOrderRevisionDraftFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionDraftFixtures();
    }

    public function test_repeat_order_creates_new_quote_draft(): void
    {
        $sourceOrder = $this->createSourceOrder();
        $draft = $this->createRepeatDraft($sourceOrder);

        $this->assertSame('quote', $draft->document_type);
        $this->assertSame(Order::COPY_TYPE_REPEAT_ORDER, $draft->copy_type);
        $this->assertNull($draft->revision_number);
        $this->assertSame('Tekrar Sipariş', $draft->copyTypeLabel());
        $this->assertSame($sourceOrder->id, $draft->source_order_id);
        $this->assertSame('order', $sourceOrder->fresh()->document_type);
    }
}
