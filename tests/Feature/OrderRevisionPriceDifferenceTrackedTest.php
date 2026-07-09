<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionPriceDifferenceTrackedTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_price_difference_is_tracked_with_helper_text(): void
    {
        [, $draft] = $this->createComparableRevisionDraft();
        $this->mutateRevisionPrice($draft, 41);

        $this->getAs($this->adminUser, $this->revisionCompareRoute($draft->fresh()))
            ->assertOk()
            ->assertSee('Fiyat farkı eski kaydı ezmeden revizyon farkı olarak izlenmelidir.');
    }
}
