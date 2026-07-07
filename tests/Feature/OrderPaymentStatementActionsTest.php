<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Services\OrderPaymentCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderPaymentStatementActionsTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_order_payment_rows_expose_source_payment_actions_not_transaction_cancel(): void
    {
        $customer = $this->createCustomerCompany('Tahsilat Action Müşteri');
        $order = $this->createOrder($customer, 'SP-PAY-ACTION-001', 9100);
        $debit = $this->syncOrderDebit($order);
        $this->createCollectionPayment($order->fresh(['customer.companyRoles', 'payments']), 2500);

        $paymentTransaction = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', OrderPaymentCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $order->fresh()->payments()->latest('id')->firstOrFail()->id)
            ->firstOrFail();

        $cancelPath = '/admin/finance/' . $order->id . '/payments/' . $order->fresh()->payments()->latest('id')->firstOrFail()->id . '/cancel';

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $debit?->current_account_id . '/transactions'))
            ->assertOk()
            ->assertSee('Tahsilatı Aç')
            ->assertSee('/admin/finance/' . $order->id, false)
            ->assertSee('Tahsilatı İptal Et')
            ->assertSee($cancelPath, false)
            ->assertDontSee('Düzenle')
            ->assertDontSee('current-account-transactions/' . $paymentTransaction->id . '/cancel', false);
    }
}
