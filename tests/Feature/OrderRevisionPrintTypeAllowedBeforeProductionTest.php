<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionPrintTypeAllowedBeforeProductionTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_print_type_change_is_controlled_before_production_starts(): void
    {
        [, $draft] = $this->createComparableRevisionDraft();
        $this->mutateRevisionPrintType($draft, 'Tampon');

        $this->getAs($this->adminUser, $this->revisionCompareRoute($draft->fresh()))
            ->assertOk()
            ->assertSee('Baskı Tipi')
            ->assertSeeText('Kontrollü Uygulanabilir');
    }
}
