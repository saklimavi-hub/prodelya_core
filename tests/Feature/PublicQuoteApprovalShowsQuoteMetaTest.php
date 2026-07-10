<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPublicQuoteApprovalFixtures;
use Tests\TestCase;

class PublicQuoteApprovalShowsQuoteMetaTest extends TestCase
{
    use BuildsPublicQuoteApprovalFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublicQuoteApprovalFixtures();
    }

    public function test_public_quote_approval_shows_quote_meta(): void
    {
        $context = $this->createPublicApprovalContext('TK-PUBLIC-META-001');

        $this->get($this->quoteApprovalShowUrl($context['request']))
            ->assertOk()
            ->assertSee('Teklif No')
            ->assertSee('Müşteri')
            ->assertSee('Teklif Tarihi')
            ->assertSee('Geçerlilik Tarihi')
            ->assertSee('TK-PUBLIC-META-001')
            ->assertSee('ABC İnşaat A.Ş.')
            ->assertSee('01.07.2026')
            ->assertSee('08.07.2026');
    }
}
