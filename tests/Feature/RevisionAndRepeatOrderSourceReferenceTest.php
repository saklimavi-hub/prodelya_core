<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionDraftFixtures;
use Tests\TestCase;

class RevisionAndRepeatOrderSourceReferenceTest extends TestCase
{
    use BuildsOrderRevisionDraftFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionDraftFixtures();
    }

    public function test_revision_and_repeat_drafts_show_source_order_reference(): void
    {
        $sourceOrder = $this->createSourceOrder();
        $revisionDraft = $this->createRevisionDraft($sourceOrder);
        $repeatDraft = $this->createRepeatDraft($sourceOrder);

        $revisionResponse = $this->getAs($this->adminUser, route('admin.promotion-quotes.show', $revisionDraft));
        $repeatResponse = $this->getAs($this->adminUser, route('admin.promotion-quotes.show', $repeatDraft));

        $revisionResponse->assertOk()->assertSee('Revize 1')->assertSee('Kaynak Sipariş: ' . $sourceOrder->document_number);
        $repeatResponse->assertOk()->assertSee('Tekrar Sipariş')->assertSee('Kaynak Sipariş: ' . $sourceOrder->document_number);
        $revisionResponse->assertSee(route('admin.orders.show', $sourceOrder), false);
        $repeatResponse->assertSee(route('admin.orders.show', $sourceOrder), false);
    }
}
