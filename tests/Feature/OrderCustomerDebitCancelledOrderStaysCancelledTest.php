<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderCustomerDebitCancelledOrderStaysCancelledTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_cancelled_order_keeps_auto_debit_cancelled_when_finance_screen_opens(): void
    {
        $customer = $this->createCustomerCompany('Cancelled Repair Müşteri');
        $order = $this->createOrder($customer, 'SP-REPAIR-CANCEL-001', 6200);
        $transaction = $this->syncOrderDebit($order);

        $order->forceFill(['status' => 'cancelled'])->save();

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/finance/' . $order->id))
            ->assertOk();

        $cancelled = $transaction->fresh();
        $this->assertSame(CurrentAccountTransaction::STATUS_CANCELLED, $cancelled->status);
        $this->assertTrue($cancelled->isCancelled());

        $this->assertSame(1, CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', 'order')
            ->where('source_id', $order->id)
            ->where('transaction_type', CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT)
            ->count());
    }
}
