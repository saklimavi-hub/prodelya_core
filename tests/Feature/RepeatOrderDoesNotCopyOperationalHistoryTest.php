<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionDraftFixtures;
use Tests\TestCase;

class RepeatOrderDoesNotCopyOperationalHistoryTest extends TestCase
{
    use BuildsOrderRevisionDraftFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionDraftFixtures();
    }

    public function test_repeat_order_does_not_copy_operational_history(): void
    {
        $sourceOrder = $this->createSourceOrder();
        $draft = $this->createRepeatDraft($sourceOrder);

        $this->assertSame(0, $draft->workForms()->count());
        $this->assertSame(0, $draft->procurements()->count());
        $this->assertSame(0, $draft->printProductions()->count());
        $this->assertSame(0, $draft->deliveries()->count());
    }
}
