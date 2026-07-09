<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionCompareTurkishTerminologyTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_compare_page_uses_expected_turkish_terms(): void
    {
        [, $draft] = $this->createComparableRevisionDraft();
        $this->addRevisionItem($draft);

        $this->getAs($this->adminUser, $this->revisionCompareRoute($draft->fresh()))
            ->assertOk()
            ->assertSee('Sipariş Revizyon Karşılaştırması')
            ->assertSee('Revizyon Karar Matrisi')
            ->assertSee('Ürün & Baskı Karşılaştırması')
            ->assertSee('Değişmedi')
            ->assertSee('Yeni Eklendi')
            ->assertDontSee('compare matrix');
    }
}
