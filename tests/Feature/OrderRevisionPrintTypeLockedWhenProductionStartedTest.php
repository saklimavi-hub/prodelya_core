<?php

namespace Tests\Feature;

use App\Models\OrderItemPrintProduction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionPrintTypeLockedWhenProductionStartedTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_print_type_change_is_locked_when_production_started(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft();
        $this->setProductionStatus($sourceOrder, OrderItemPrintProduction::STATUS_INTERNAL);
        $this->mutateRevisionPrintType($draft, 'Lazer');

        $this->getAs($this->adminUser, $this->revisionCompareRoute($draft->fresh()))
            ->assertOk()
            ->assertSee('Baskı Tipi')
            ->assertSeeText('Kilitli');
    }
}
