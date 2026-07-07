<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Services\OrderCurrentAccountDebitSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderCustomerDebitSyncTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_order_sync_creates_customer_debit_transaction_for_customer_account(): void
    {
        $customer = $this->createCustomerCompany('Sync Test Müşteri');
        $order = $this->createOrder($customer, 'SP-SYNC-001', 18000);

        $transaction = $this->syncOrderDebit($order);

        $this->assertNotNull($transaction);
        $this->assertSame(CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT, $transaction->transaction_type);
        $this->assertSame(OrderCurrentAccountDebitSyncService::SOURCE_TYPE, $transaction->source_type);
        $this->assertSame($order->id, $transaction->source_id);
        $this->assertSame('18000.00', (string) $transaction->amount);
        $this->assertSame('TL', $transaction->currency);
        $this->assertSame(CurrentAccountTransaction::STATUS_OPEN, $transaction->status);
        $this->assertSame($customer->id, $order->customer_company_id);

        $account = $transaction->currentAccount()->firstOrFail();
        $this->assertSame($this->tenant->id, $account->tenant_account_id);
        $this->assertTrue($account->hasRole('customer'));
    }
}
