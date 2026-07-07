<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderShowSensitiveLeakTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_order_show_hides_sensitive_and_technical_fields(): void
    {
        $order = $this->createConvertedOrderForShow();

        $response = $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat']));

        $response->assertOk()
            ->assertDontSeeText('supplier_cost')
            ->assertDontSeeText('profit')
            ->assertDontSeeText('group_code')
            ->assertDontSeeText('source_type')
            ->assertDontSeeText('current_account_id')
            ->assertDontSeeText('meta_json')
            ->assertDontSeeText('file_path')
            ->assertDontSeeText('physical_path');
    }
}
