<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPublicQuoteApprovalFixtures;
use Tests\TestCase;

class PublicQuoteApprovalTemplateLayoutTest extends TestCase
{
    use BuildsPublicQuoteApprovalFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublicQuoteApprovalFixtures();
    }

    public function test_public_quote_approval_template_layout_is_rendered(): void
    {
        $context = $this->createPublicApprovalContext();

        $this->get($this->quoteApprovalShowUrl($context['request']))
            ->assertOk()
            ->assertSee('Teklifinizi İnceleyin')
            ->assertSee('Teklif Kalemleri')
            ->assertSee('Fiyat Özeti')
            ->assertSee('Kararınızı Bildirin');
    }
}
