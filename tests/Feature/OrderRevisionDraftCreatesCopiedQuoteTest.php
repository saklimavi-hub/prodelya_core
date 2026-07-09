<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionDraftFixtures;
use Tests\TestCase;

class OrderRevisionDraftCreatesCopiedQuoteTest extends TestCase
{
    use BuildsOrderRevisionDraftFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionDraftFixtures();
    }

    public function test_revision_draft_creates_copied_quote_without_touching_source_order(): void
    {
        $sourceOrder = $this->createSourceOrder();

        $draft = $this->createRevisionDraft($sourceOrder);

        $this->assertSame('quote', $draft->document_type);
        $this->assertSame(Order::COPY_TYPE_REVISION, $draft->copy_type);
        $this->assertSame(1, $draft->revision_number);
        $this->assertSame($sourceOrder->id, $draft->source_order_id);
        $this->assertSame('draft', $draft->status);
        $this->assertSame('quote', $draft->workflow_status);
        $this->assertSame($sourceOrder->document_number, $draft->sourceOrder?->document_number);
        $this->assertSame('order', $sourceOrder->fresh()->document_type);
        $this->assertCount(1, $draft->items);
    }
}
