<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionDecisionMatrixRulesTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_decision_matrix_shows_all_expected_labels(): void
    {
        [, $draft] = $this->createComparableRevisionDraft();
        $this->mutateRevisionQuantity($draft, 150);
        $this->mutateRevisionPrice($draft, 37);
        $this->mutateRevisionPrintNote($draft, 'Yeni baskı notu');

        $this->getAs($this->adminUser, $this->revisionCompareRoute($draft->fresh()))
            ->assertOk()
            ->assertSee('Ürün Değişimi')
            ->assertSee('Adet Değişimi')
            ->assertSee('Baskı Tipi')
            ->assertSee('Baskı Notu')
            ->assertSee('Fiyat')
            ->assertSee('Teslim Bilgisi')
            ->assertSeeText('Uygulanabilir')
            ->assertSeeText('Kontrollü Uygulanabilir')
            ->assertSeeText('Değişiklik Yok');
    }
}
