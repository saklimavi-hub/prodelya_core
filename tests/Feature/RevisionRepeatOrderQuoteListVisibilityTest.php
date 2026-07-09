<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionDraftFixtures;
use Tests\TestCase;

class RevisionRepeatOrderQuoteListVisibilityTest extends TestCase
{
    use BuildsOrderRevisionDraftFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionDraftFixtures();
    }

    public function test_copied_drafts_are_visible_in_quote_list_and_hidden_from_order_list(): void
    {
        $sourceOrder = $this->createSourceOrder();
        $revisionDraft = $this->createRevisionDraft($sourceOrder);
        $repeatDraft = $this->createRepeatDraft($sourceOrder);

        $this->getAs($this->adminUser, route('admin.promotion-quotes.index'))
            ->assertOk()
            ->assertSee($revisionDraft->document_number)
            ->assertSee($repeatDraft->document_number);

        $this->getAs($this->adminUser, route('admin.orders.index'))
            ->assertOk()
            ->assertDontSee($revisionDraft->document_number)
            ->assertDontSee($repeatDraft->document_number);
    }
}
