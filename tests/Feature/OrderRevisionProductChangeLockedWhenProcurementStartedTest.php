<?php

namespace Tests\Feature;

use App\Models\OrderItemProcurement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionProductChangeLockedWhenProcurementStartedTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_product_change_is_locked_when_procurement_started(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft();
        $this->setProcurementStatus($sourceOrder, OrderItemProcurement::STATUS_SUPPLIER_ORDERED);
        $this->mutateRevisionProduct($draft, 'REV-002', 'Yeni Revizyon Ürünü');

        $this->getAs($this->adminUser, $this->revisionCompareRoute($draft->fresh()))
            ->assertOk()
            ->assertSee('Ürün Değişimi')
            ->assertSeeText('Kilitli');
    }
}
