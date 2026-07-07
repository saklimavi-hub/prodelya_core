<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class CurrentAccountStatementOrderSourceNoCancelActionTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_order_source_statement_row_hides_cancel_action_and_marks_source(): void
    {
        $customer = $this->createCustomerCompany('Sipariş Kaynaklı Cari');
        $order = $this->createOrder($customer, 'SP-ORD-CANCEL-001', 7200);
        $transaction = $this->syncOrderDebit($order);

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $transaction?->current_account_id . '/transactions'))
            ->assertOk()
            ->assertSee('Sipariş kaynaklı')
            ->assertSee($order->document_number)
            ->assertDontSee('Cari ekranından manuel iptal')
            ->assertDontSee('current-account-transactions/' . $transaction?->id . '/cancel', false);
    }
}
