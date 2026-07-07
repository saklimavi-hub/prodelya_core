<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderProcessKeepsCustomerDebitVisibleTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_customer_debit_stays_visible_while_order_moves_through_operations(): void
    {
        $customer = $this->createCustomerCompany('Process Visibility Müşteri');
        $order = $this->createOrder($customer, 'SP-PROC-001', 12500);
        $transaction = $this->syncOrderDebit($order);

        $workflowStatuses = [
            'graphic_pending',
            'graphic_completed',
            'procurement_pending',
            'production_started',
            'production_completed',
            'delivery_created',
            'delivered',
        ];

        foreach ($workflowStatuses as $status) {
            $order->forceFill(['workflow_status' => $status])->saveQuietly();

            $this->assertDatabaseHas('current_account_transactions', [
                'id' => $transaction?->id,
                'status' => CurrentAccountTransaction::STATUS_OPEN,
                'source_type' => 'order',
                'source_id' => $order->id,
            ]);
        }

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $transaction?->current_account_id . '/transactions'))
            ->assertOk()
            ->assertSee('Siparişten oluşan müşteri borcu')
            ->assertSee($order->document_number);
    }
}
