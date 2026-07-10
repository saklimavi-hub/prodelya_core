<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsPromotionQuoteDetailFixture;
use Tests\TestCase;

class PromotionQuoteDetailPhoneHelperTextTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPromotionQuoteDetailFixture;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPromotionQuoteDetailFixture();
    }

    public function test_phone_helper_is_short_and_example_based(): void
    {
        $response = $this->showQuote($this->createPromotionQuote());

        $response->assertOk();
        $response->assertSee('Örnek: 05** *** ** ** veya 0212 *** ** **');
        $response->assertDontSee('02125018233');
        $response->assertDontSee('902125018233');
        $response->assertDontSee('0212 501 82 33 / 0532 123 45 67');
    }
}
