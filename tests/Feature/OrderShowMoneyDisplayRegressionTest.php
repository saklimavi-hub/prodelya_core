<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderShowMoneyDisplayRegressionTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_finance_tab_does_not_render_plus_sign_for_positive_amounts(): void
    {
        $order = $this->createConvertedOrderForShow();

        $response = $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', ['order' => $order, 'tab' => 'finans']));

        $response->assertOk();

        $this->assertDoesNotMatchRegularExpression('/\+\d{1,3}(\.\d{3})*,\d{2}\sTL/u', $response->getContent());
    }
}
