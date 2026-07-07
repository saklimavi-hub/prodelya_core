<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class FinanceTerminologyRegressionTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_finance_screen_avoids_old_balance_phrases_and_keeps_order_language_clear(): void
    {
        $customer = $this->createCustomerCompany('Finance Terminoloji Müşteri');
        $order = $this->createOrder($customer, 'SP-FIN-TERM-001', 10000);

        $this->syncOrderDebit($order);

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/finance/' . $order->id))
            ->assertOk()
            ->assertSee('Sipariş Müşteri Borcu')
            ->assertSee('Tahsilat')
            ->assertSee('Kalan Alacak')
            ->assertDontSee('Bize Borçlu')
            ->assertDontSee('Biz Borçluyuz');
    }
}
