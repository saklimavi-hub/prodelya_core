<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPublicQuoteApprovalFixtures;
use Tests\TestCase;

class PublicQuoteApprovalNoSensitiveLeakTest extends TestCase
{
    use BuildsPublicQuoteApprovalFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublicQuoteApprovalFixtures();
    }

    public function test_public_quote_approval_does_not_leak_sensitive_fields(): void
    {
        $context = $this->createPublicApprovalContext('TK-PUBLIC-SAFE-001');

        $this->get($this->quoteApprovalShowUrl($context['request']))
            ->assertOk()
            ->assertDontSee('supplier_cost', false)
            ->assertDontSee('purchase_price', false)
            ->assertDontSee('group_code', false)
            ->assertDontSee('file_path', false)
            ->assertDontSee('tenant_id', false)
            ->assertDontSee('current_account_id', false)
            ->assertDontSee('transaction_id', false)
            ->assertDontSee('meta_json', false)
            ->assertDontSee('payload', false)
            ->assertDontSeeText('profit')
            ->assertDontSeeText('projection');
    }
}
