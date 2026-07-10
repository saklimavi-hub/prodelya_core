<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPublicQuoteApprovalFixtures;
use Tests\TestCase;

class PublicQuoteApprovalShowsItemsAndPrintRowsTest extends TestCase
{
    use BuildsPublicQuoteApprovalFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublicQuoteApprovalFixtures();
    }

    public function test_public_quote_approval_shows_items_and_print_rows(): void
    {
        $context = $this->createPublicApprovalContext('TK-PUBLIC-ITEMS-001');

        $this->get($this->quoteApprovalShowUrl($context['request']))
            ->assertOk()
            ->assertSee('1. Public Approval Ana Ürün')
            ->assertSee('2. Public Approval Yardımcı Ürün')
            ->assertSee('UV Baskı · Çift Taraf Baskı')
            ->assertSee('Müşteriye görünür baskı notu')
            ->assertSee('Baskı Toplamı')
            ->assertSee('1.600,00 TL');
    }
}
