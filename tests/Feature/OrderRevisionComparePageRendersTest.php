<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderRevisionCompareFixtures;
use Tests\TestCase;

class OrderRevisionComparePageRendersTest extends TestCase
{
    use BuildsOrderRevisionCompareFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderRevisionCompareFixtures();
    }

    public function test_revision_compare_page_renders_and_links_from_quote_pages(): void
    {
        [$sourceOrder, $draft] = $this->createComparableRevisionDraft();

        $compareUrl = $this->revisionCompareRoute($draft);

        $this->getAs($this->adminUser, route('admin.promotion-quotes.show', $draft))
            ->assertOk()
            ->assertSee('Revizyon Karşılaştır')
            ->assertSee($compareUrl, false);

        $this->getAs($this->adminUser, route('admin.promotion-quotes.edit', $draft))
            ->assertOk()
            ->assertSee('Revizyon Karşılaştır')
            ->assertSee($compareUrl, false);

        $this->getAs($this->adminUser, $compareUrl)
            ->assertOk()
            ->assertSee('Sipariş Revizyon Karşılaştırması')
            ->assertSee($sourceOrder->document_number)
            ->assertSee($draft->document_number)
            ->assertSee('Revizyonu Uygula')
            ->assertSee('Uygulanacak Değişiklik')
            ->assertSee('Vazgeç');
    }
}
