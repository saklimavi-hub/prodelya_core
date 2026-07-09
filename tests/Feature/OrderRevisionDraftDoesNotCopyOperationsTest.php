<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionDraftFixtures;
use Tests\TestCase;

class OrderRevisionDraftDoesNotCopyOperationsTest extends TestCase
{
    use BuildsOrderRevisionDraftFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionDraftFixtures();
    }

    public function test_revision_draft_does_not_copy_operational_records(): void
    {
        $sourceOrder = $this->createSourceOrder();
        $draft = $this->createRevisionDraft($sourceOrder);

        $this->assertGreaterThan(0, $sourceOrder->workForms()->count());
        $this->assertGreaterThan(0, $sourceOrder->procurements()->count());
        $this->assertGreaterThan(0, $sourceOrder->printProductions()->count());
        $this->assertGreaterThan(0, $sourceOrder->deliveries()->count());
        $this->assertSame(0, $draft->workForms()->count());
        $this->assertSame(0, $draft->procurements()->count());
        $this->assertSame(0, $draft->printProductions()->count());
        $this->assertSame(0, $draft->deliveries()->count());
        $this->assertSame(0, $draft->items()->firstOrFail()->workForm()->count());
    }
}
