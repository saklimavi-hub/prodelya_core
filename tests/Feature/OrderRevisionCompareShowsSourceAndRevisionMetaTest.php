<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionCompareShowsSourceAndRevisionMetaTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_compare_page_shows_source_and_revision_meta(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft();

        $this->getAs($this->adminUser, $this->revisionCompareRoute($draft))
            ->assertOk()
            ->assertSee('Kaynak Sipariş')
            ->assertSee($sourceOrder->document_number)
            ->assertSee('Revize 1')
            ->assertSee($draft->document_number)
            ->assertSee('Revizyon Teklifi')
            ->assertSee('Revizyon Durumu');
    }
}
