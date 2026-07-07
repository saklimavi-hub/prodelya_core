<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class FinanceOrderReceivableSummaryTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_finance_screen_shows_receivable_status_collection_link_and_remaining_balance(): void
    {
        $customer = $this->createCustomerCompany('Finance Özet Müşteri');
        $order = $this->createOrder($customer, 'SP-FIN-001', 18000);

        $this->syncOrderDebit($order);
        $this->createCollectionPayment($order->fresh(['customer.companyRoles', 'payments']), 5000);

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/finance/' . $order->id))
            ->assertOk()
            ->assertSee('Sipariş Müşteri Borcu')
            ->assertSee('Oluşturuldu')
            ->assertSee('Cari Ekstrede Gör')
            ->assertSee('13.000,00 TL');

        $this->actingAs($this->limitedUser, 'web')
            ->get($this->tenantUrl('/admin/finance/' . $order->id))
            ->assertForbidden();
    }
}
