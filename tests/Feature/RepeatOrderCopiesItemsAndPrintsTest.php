<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionDraftFixtures;
use Tests\TestCase;

class RepeatOrderCopiesItemsAndPrintsTest extends TestCase
{
    use BuildsOrderRevisionDraftFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionDraftFixtures();
    }

    public function test_repeat_order_copies_items_and_prints_to_new_quote_draft(): void
    {
        $sourceOrder = $this->createSourceOrder();
        $draft = $this->createRepeatDraft($sourceOrder);

        $this->assertCount($sourceOrder->items->count(), $draft->items);
        $this->assertCount($sourceOrder->items->first()->prints->count(), $draft->items->first()->prints);
        $this->assertSame($sourceOrder->items->first()->product_code, $draft->items->first()->product_code);
        $this->assertSame($sourceOrder->items->first()->prints->first()->note, $draft->items->first()->prints->first()->note);
    }
}
