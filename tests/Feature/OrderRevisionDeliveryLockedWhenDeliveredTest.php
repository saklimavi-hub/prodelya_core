<?php

namespace Tests\Feature;

use App\Models\OrderItemWorkFormDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionDeliveryLockedWhenDeliveredTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_delivery_change_is_locked_when_order_delivered(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft();
        $this->setDeliveryStatus($sourceOrder, OrderItemWorkFormDelivery::STATUS_DELIVERED);
        $this->mutateRevisionDeliveryType($draft, 'Kurye');

        $this->getAs($this->adminUser, $this->revisionCompareRoute($draft->fresh()))
            ->assertOk()
            ->assertSee('Teslim Bilgisi')
            ->assertSeeText('Kilitli');
    }
}
