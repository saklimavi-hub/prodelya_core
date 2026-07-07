<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Services\OrderCurrentAccountDebitSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderCustomerDebitNoDuplicateAcrossScreensTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_order_debit_stays_single_across_order_finance_and_payment_flows(): void
    {
        $customer = $this->createCustomerCompany('No Duplicate Müşteri');
        $order = $this->createOrder($customer, 'SP-NODUP-001', 18000);
        $transaction = $this->syncOrderDebit($order);

        $this->actingAs($this->financeUser, 'web')->get($this->tenantUrl('/admin/orders/' . $order->id))->assertOk();
        $this->actingAs($this->financeUser, 'web')->get($this->tenantUrl('/admin/finance/' . $order->id))->assertOk();

        $this->createCollectionPayment($order->fresh(['customer.companyRoles', 'payments']), 5000);
        app(OrderCurrentAccountDebitSyncService::class)->syncOrder($order->fresh(['customer.companyRoles', 'payments']), $this->financeUser);

        $this->assertSame(1, CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', OrderCurrentAccountDebitSyncService::SOURCE_TYPE)
            ->where('source_id', $order->id)
            ->where('transaction_type', CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT)
            ->count());

        $freshTransaction = $transaction?->fresh();
        $this->assertSame(CurrentAccountTransaction::STATUS_PARTIALLY_PAID, $freshTransaction?->status);
    }
}
