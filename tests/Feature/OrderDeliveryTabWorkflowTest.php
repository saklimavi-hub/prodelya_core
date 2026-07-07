<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderDeliveryTabWorkflowTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_delivery_tab_renders_steps_and_hides_technical_fields(): void
    {
        $order = $this->createConvertedOrderForShow();

        $response = $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::ORDER_SHOW_HOST])
            ->get(route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat']));

        $response->assertOk()
            ->assertSee('Teslimata Hazırla')
            ->assertSee('Koli Planı')
            ->assertSee('Etiket Oluştur')
            ->assertSee('Teslim Bilgisi')
            ->assertSee('Teslim Edildi')
            ->assertDontSee('source_type', false)
            ->assertDontSee('current_account_id', false)
            ->assertDontSee('meta_json', false)
            ->assertDontSee('file_path', false);
    }
}
