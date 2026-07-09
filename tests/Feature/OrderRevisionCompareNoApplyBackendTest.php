<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionCompareNoApplyBackendTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_compare_page_shows_apply_action_with_controlled_copy(): void
    {
        [, $draft] = $this->createComparableRevisionDraft();

        $this->getAs($this->adminUser, $this->revisionCompareRoute($draft))
            ->assertOk()
            ->assertSee('Revizyonu Uygula')
            ->assertSee('Yalnız uygulanabilir ve kontrollü uygulanabilir ticari alanlar işlenir; kilitli ve manuel alanlar atlanır.');
    }
}
