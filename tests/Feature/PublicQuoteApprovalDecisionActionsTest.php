<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPublicQuoteApprovalFixtures;
use Tests\TestCase;

class PublicQuoteApprovalDecisionActionsTest extends TestCase
{
    use BuildsPublicQuoteApprovalFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublicQuoteApprovalFixtures();
    }

    public function test_public_quote_approval_decision_actions_are_visible(): void
    {
        $context = $this->createPublicApprovalContext('TK-PUBLIC-ACTIONS-001');

        $this->get($this->quoteApprovalShowUrl($context['request']))
            ->assertOk()
            ->assertSee('Teklifi Onayla')
            ->assertSee('Revize İste')
            ->assertSee('Teklifi Reddet')
            ->assertSee(route('public.quotes.approval.approve', ['token' => $context['request']->token]), false)
            ->assertSee(route('public.quotes.approval.revision', ['token' => $context['request']->token]), false)
            ->assertSee(route('public.quotes.approval.reject', ['token' => $context['request']->token]), false);
    }
}
