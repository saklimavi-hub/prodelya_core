<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionCompareNoSensitiveLeakTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_compare_page_does_not_leak_sensitive_fields(): void
    {
        [, $draft] = $this->createComparableRevisionDraft();

        $response = $this->getAs($this->adminUser, $this->revisionCompareRoute($draft));

        $response->assertOk()
            ->assertDontSee('SUPPLIER-COST-HIDDEN')
            ->assertDontSee('MARGIN-HIDDEN')
            ->assertDontSee('RAW-HIDDEN')
            ->assertDontSee('PROJECTION-HIDDEN')
            ->assertDontSee('PAYLOAD-HIDDEN')
            ->assertDontSee('HIDDEN-GROUP-CODE')
            ->assertDontSee('/tmp/private.pdf');
    }
}
