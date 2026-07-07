<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderFinanceSensitiveLeakTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_finance_screen_does_not_render_sensitive_or_technical_fields(): void
    {
        $customer = $this->createCustomerCompany('Sızıntı Test Müşteri');
        $order = $this->createOrder($customer, 'SP-OFS-LEAK-001', 18000);
        $this->syncOrderDebit($order);

        $response = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/finance/' . $order->id));

        $response->assertOk()
            ->assertDontSee('source_type')
            ->assertDontSee('source_id')
            ->assertDontSee('transaction_id')
            ->assertDontSee('tenant_id')
            ->assertDontSee('current_account_id')
            ->assertDontSee('meta_json')
            ->assertDontSee('supplier_cost')
            ->assertDontSee('profit')
            ->assertDontSee('group_code')
            ->assertDontSee('raw')
            ->assertDontSee('projection')
            ->assertDontSee('secret')
            ->assertDontSee('api key')
            ->assertDontSee('file_path');
    }
}
