<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderCustomerDebitStatementActionRegressionTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_order_customer_debit_stays_visible_in_borc_column_without_cancel_action(): void
    {
        $customer = $this->createCustomerCompany('Action Regression Müşteri');
        $order = $this->createOrder($customer, 'SP-ACTION-001', 11800);
        $transaction = $this->syncOrderDebit($order);

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $transaction?->current_account_id . '/transactions'))
            ->assertOk()
            ->assertSee('Borç')
            ->assertSee('Siparişten oluşan müşteri borcu')
            ->assertSee('Sipariş kaynaklı')
            ->assertSee($transaction?->formattedAmount() ?? '')
            ->assertDontSee('current-account-transactions/' . $transaction?->id . '/cancel', false)
            ->assertDontSee('Bize Borçlu')
            ->assertDontSee('Biz Borçluyuz');
    }
}
