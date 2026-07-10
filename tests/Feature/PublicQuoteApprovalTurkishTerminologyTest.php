<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPublicQuoteApprovalFixtures;
use Tests\TestCase;

class PublicQuoteApprovalTurkishTerminologyTest extends TestCase
{
    use BuildsPublicQuoteApprovalFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublicQuoteApprovalFixtures();
    }

    public function test_public_quote_approval_uses_turkish_terminology_cleanly(): void
    {
        $context = $this->createPublicApprovalContext('TK-PUBLIC-TR-001');

        $this->get($this->quoteApprovalShowUrl($context['request']))
            ->assertOk()
            ->assertSee('Teklifinizi İnceleyin')
            ->assertSee('Kararınızı Bildirin')
            ->assertSee('Geçerlilik Tarihi')
            ->assertSee('Genel Toplam')
            ->assertDontSee('Teklifinizi Inceleyin')
            ->assertDontSee('Gecerlilik')
            ->assertDontSee('Musteri')
            ->assertDontSee('Revize Iste')
            ->assertDontSee('approval_token', false)
            ->assertDontSee('status_code', false)
            ->assertDontSee('customer_id', false)
            ->assertDontSee('tenant_id', false);
    }
}
