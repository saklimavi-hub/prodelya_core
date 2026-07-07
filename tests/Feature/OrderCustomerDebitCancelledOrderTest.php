<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Services\OrderCurrentAccountDebitSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderCustomerDebitCancelledOrderTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_cancelled_order_does_not_create_new_debit_and_cancels_existing_auto_debit(): void
    {
        $customer = $this->createCustomerCompany('Cancelled Müşteri');
        $cancelledOrder = $this->createOrder($customer, 'SP-CANCEL-NEW', 1200, [
            'status' => 'cancelled',
        ]);

        $result = $this->syncOrderDebit($cancelledOrder);

        $this->assertNull($result);
        $this->assertDatabaseMissing('current_account_transactions', [
            'tenant_account_id' => $this->tenant->id,
            'source_type' => OrderCurrentAccountDebitSyncService::SOURCE_TYPE,
            'source_id' => $cancelledOrder->id,
        ]);

        $activeOrder = $this->createOrder($customer, 'SP-CANCEL-OLD', 5400);
        $transaction = $this->syncOrderDebit($activeOrder);

        $this->assertNotNull($transaction);

        $activeOrder->forceFill(['status' => 'cancelled'])->save();
        $cancelledTransaction = $this->syncOrderDebit($activeOrder->fresh(['customer.companyRoles', 'payments']));

        $this->assertNotNull($cancelledTransaction);
        $this->assertSame(CurrentAccountTransaction::STATUS_CANCELLED, $cancelledTransaction->status);
        $this->assertTrue($cancelledTransaction->isCancelled());
    }
}
