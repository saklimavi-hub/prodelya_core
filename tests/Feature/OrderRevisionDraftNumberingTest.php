<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionDraftFixtures;
use Tests\TestCase;

class OrderRevisionDraftNumberingTest extends TestCase
{
    use BuildsOrderRevisionDraftFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionDraftFixtures();
    }

    public function test_revision_numbering_increments_as_revize_1_and_revize_2(): void
    {
        $sourceOrder = $this->createSourceOrder();

        $firstDraft = $this->createRevisionDraft($sourceOrder);
        $secondDraft = $this->createRevisionDraft($sourceOrder);

        $this->assertSame(1, $firstDraft->revision_number);
        $this->assertSame('Revize 1', $firstDraft->copyTypeLabel());
        $this->assertSame(2, $secondDraft->revision_number);
        $this->assertSame('Revize 2', $secondDraft->copyTypeLabel());
    }
}
