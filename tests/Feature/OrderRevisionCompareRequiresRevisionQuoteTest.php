<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionCompareRequiresRevisionQuoteTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_non_revision_quotes_are_rejected(): void
    {
        $sourceOrder = $this->createSourceOrder();
        $repeatDraft = $this->createRepeatDraft($sourceOrder);

        $this->getAs($this->adminUser, $this->revisionCompareRoute($repeatDraft))
            ->assertNotFound();
    }
}
