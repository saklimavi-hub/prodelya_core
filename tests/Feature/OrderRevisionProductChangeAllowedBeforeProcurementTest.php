<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionProductChangeAllowedBeforeProcurementTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_product_change_is_controlled_before_procurement_starts(): void
    {
        [, $draft] = $this->createComparableRevisionDraft();
        $this->mutateRevisionProduct($draft, 'REV-777', 'Kontrollü Revizyon Ürünü');

        $this->getAs($this->adminUser, $this->revisionCompareRoute($draft->fresh()))
            ->assertOk()
            ->assertSee('Ürün Değişimi')
            ->assertSeeText('Kontrollü Uygulanabilir');
    }
}
