<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionComparePermissionTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_unauthorized_user_cannot_open_compare_page(): void
    {
        [, $draft] = $this->createComparableRevisionDraft();

        $this->getAs($this->unauthorizedUser, $this->revisionCompareRoute($draft))
            ->assertForbidden();
    }
}
