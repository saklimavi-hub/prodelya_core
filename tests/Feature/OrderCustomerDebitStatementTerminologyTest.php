<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderCustomerDebitStatementTerminologyTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_order_debit_appears_in_statement_with_borc_alacak_bakiye_standard(): void
    {
        $customer = $this->createCustomerCompany('Terminology Müşteri');
        $order = $this->createOrder($customer, 'SP-TERM-001', 9000);
        $transaction = $this->syncOrderDebit($order);

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $transaction?->current_account_id . '/transactions'))
            ->assertOk()
            ->assertSee('Borç')
            ->assertSee('Alacak')
            ->assertSee('Bakiye')
            ->assertSee('Siparişten oluşan müşteri borcu')
            ->assertSee($order->document_number)
            ->assertDontSee('Bize Borçlu')
            ->assertDontSee('Biz Borçluyuz');
    }
}
