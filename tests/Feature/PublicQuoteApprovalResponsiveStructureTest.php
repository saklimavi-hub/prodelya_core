<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPublicQuoteApprovalFixtures;
use Tests\TestCase;

class PublicQuoteApprovalResponsiveStructureTest extends TestCase
{
    use BuildsPublicQuoteApprovalFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublicQuoteApprovalFixtures();
    }

    public function test_public_quote_approval_has_responsive_structure_classes(): void
    {
        $context = $this->createPublicApprovalContext('TK-PUBLIC-RESP-001');

        $this->get($this->quoteApprovalShowUrl($context['request']))
            ->assertOk()
            ->assertSee('quote-approval-layout', false)
            ->assertSee('quote-approval-sidebar', false)
            ->assertSee('quote-approval-mobile-summary', false)
            ->assertSee('quote-approval-desktop-summary', false)
            ->assertSee('quote-approval-decision-grid', false)
            ->assertSee('quote-approval-responsive-sidebar', false);
    }
}
