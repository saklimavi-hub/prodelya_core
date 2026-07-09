<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionLineComparisonShowsSourceAndRevisionTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_line_comparison_shows_source_and_revision_values(): void
    {
        [, $draft] = $this->createComparableRevisionDraft();
        $this->mutateRevisionQuantity($draft, 130);
        $this->mutateRevisionPrintNote($draft, 'Revize baskı notu');

        $this->getAs($this->adminUser, $this->revisionCompareRoute($draft->fresh()))
            ->assertOk()
            ->assertSee('Kaynak Sipariş')
            ->assertSee('Revizyon Teklifi')
            ->assertSee('Kaynak Test Ürünü')
            ->assertSee('130 Adet')
            ->assertSee('Revize baskı notu')
            ->assertSee('Baskı 1.1');
    }
}
