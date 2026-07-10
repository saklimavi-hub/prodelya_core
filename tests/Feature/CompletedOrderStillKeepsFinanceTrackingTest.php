<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class CompletedOrderStillKeepsFinanceTrackingTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_completed_order_keeps_finance_tracking_accessible(): void
    {
        $customer = $this->createCustomerCompany('Tamamlanan Finans Müşteri');
        $order = $this->createOrder($customer, 'SP-FIN-COMP-001', 18000, [
            'valid_until' => '2026-07-10',
        ]);

        $this->syncOrderDebit($order);
        $this->createCollectionPayment($order->fresh(['customer.companyRoles', 'payments']), 5000);

        $order->update([
            'status' => 'completed',
        ]);

        $listResponse = $this->actingAs($this->financeUser, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index', ['status' => 'completed']));

        $financeResponse = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/finance/' . $order->id));

        $listResponse->assertOk()->assertSee($order->document_number);
        $financeResponse->assertOk()
            ->assertSee($order->document_number)
            ->assertSee('Müşteri Borcu')
            ->assertSee('Kalan Bakiye');
    }
}
