<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Services\OrderCurrentAccountDebitSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderCustomerDebitIdempotencyTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_same_order_sync_does_not_create_duplicate_customer_debit(): void
    {
        $order = $this->createOrder($this->createCustomerCompany('Idempotent Müşteri'), 'SP-IDEMP-001', 9600);

        $first = $this->syncOrderDebit($order);
        $second = $this->syncOrderDebit($order);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', OrderCurrentAccountDebitSyncService::SOURCE_TYPE)
            ->where('source_id', $order->id)
            ->where('transaction_type', CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT)
            ->count());
    }
}
