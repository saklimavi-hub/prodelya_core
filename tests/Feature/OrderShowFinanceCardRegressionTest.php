<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderShowFinanceCardRegressionTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_order_show_keeps_short_finance_card_and_finance_summary_link(): void
    {
        $customer = $this->createCustomerCompany('Sipariş Kartı Müşteri');
        $order = $this->createOrder($customer, 'SP-OFS-CARD-001', 18000);
        $this->syncOrderDebit($order);
        $this->createCollectionPayment($order->fresh(['customer.companyRoles', 'payments']), 5000);

        $response = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/orders/' . $order->id));

        $response->assertOk()
            ->assertSee('Finans')
            ->assertSee('Kalan Bakiye')
            ->assertSee('Kısmi tahsil edildi')
            ->assertSee('Finans Özeti')
            ->assertSee(route('admin.finance.show', $order), false);

    }

    public function test_order_show_hides_finance_card_for_user_without_finance_permission(): void
    {
        $customer = $this->createCustomerCompany('Sipariş Kartı Yetkisiz');
        $order = $this->createOrder($customer, 'SP-OFS-CARD-002', 18000);

        $response = $this->actingAs($this->limitedUser, 'web')
            ->get($this->tenantUrl('/admin/orders/' . $order->id));

        $response->assertOk()
            ->assertDontSee('Finans Özeti')
            ->assertDontSee(route('admin.finance.show', $order), false);
    }
}
