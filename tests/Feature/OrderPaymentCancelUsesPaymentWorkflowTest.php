<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Services\CurrentAccountTransactionService;
use App\Services\OrderPaymentCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderPaymentCancelUsesPaymentWorkflowTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_order_payment_rows_use_payment_cancel_workflow_instead_of_transaction_cancel(): void
    {
        $customer = $this->createCustomerCompany('Tahsilat Workflow Müşteri');
        $order = $this->createOrder($customer, 'SP-PAY-WF-001', 15000);
        $debit = $this->syncOrderDebit($order);
        $this->createCollectionPayment($order->fresh(['customer.companyRoles', 'payments']), 5000);

        $payment = $order->fresh()->payments()->latest('id')->firstOrFail();
        $paymentTransaction = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', OrderPaymentCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $payment->id)
            ->firstOrFail();

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/current-account-transactions/' . $paymentTransaction->id . '/cancel'), [
                'cancellation_reason' => 'Ham transaction cancel denemesi',
            ])
            ->assertForbidden();

        $this->actingAs($this->financeUser, 'web')
            ->patch(route('admin.finance.payments.cancel', ['order' => $order, 'payment' => $payment]), [
                'cancel_note' => 'Statement kaynak cancel',
            ])
            ->assertRedirect(route('admin.finance.show', $order));

        $this->assertNotNull($payment->fresh()->cancelled_at);
        $this->assertTrue($paymentTransaction->fresh()->isCancelled());

        $accountSummary = app(CurrentAccountTransactionService::class)->getAccountSummary($debit->fresh()->currentAccount);
        $this->assertSame(15000.0, $accountSummary['currencies']['TL']['debit_total']);
        $this->assertSame(0.0, $accountSummary['currencies']['TL']['credit_total']);
        $this->assertSame(15000.0, $accountSummary['currencies']['TL']['balance']);
    }
}
