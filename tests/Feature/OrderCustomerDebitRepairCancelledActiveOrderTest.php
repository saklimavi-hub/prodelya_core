<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderCustomerDebitRepairCancelledActiveOrderTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_active_order_debit_is_repaired_when_old_manual_cancel_left_it_cancelled(): void
    {
        $customer = $this->createCustomerCompany('Repair Aktif Sipariş');
        $order = $this->createOrder($customer, 'SP-REPAIR-001', 18450);
        $transaction = $this->syncOrderDebit($order);

        $transaction->forceFill([
            'status' => CurrentAccountTransaction::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Cari ekranından manuel iptal',
        ])->save();

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/finance/' . $order->id))
            ->assertOk();

        $repaired = $transaction->fresh();
        $this->assertSame(CurrentAccountTransaction::STATUS_OPEN, $repaired->status);
        $this->assertNull($repaired->cancelled_at);
        $this->assertNull($repaired->cancellation_reason);
        $this->assertSame('18450.00', (string) $repaired->amount);

        $this->assertSame(1, CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', 'order')
            ->where('source_id', $order->id)
            ->where('transaction_type', CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT)
            ->count());

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $repaired->current_account_id . '/transactions'))
            ->assertOk()
            ->assertSee('Sipariş kaynaklı')
            ->assertDontSee('İptal edildi')
            ->assertDontSee('Cari ekranından manuel iptal');
    }
}
