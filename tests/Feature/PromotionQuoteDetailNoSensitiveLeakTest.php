<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailNoSensitiveLeakTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_sensitive_strings_do_not_leak(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        foreach ([
            'supplier_cost',
            'purchase_price',
            'profit',
            'margin',
            'raw',
            'projection',
            'group_code',
            'file_path',
            'tenant_id',
            'current_account_id',
            'transaction_id',
            'meta_json',
            'payload',
        ] as $forbidden) {
            $response->assertDontSeeText($forbidden, false);
        }
    }
}
