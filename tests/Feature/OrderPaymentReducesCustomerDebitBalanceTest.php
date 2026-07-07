<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Services\CurrentAccountBalanceSummaryService;
use App\Services\OrderCurrentAccountDebitSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class OrderPaymentReducesCustomerDebitBalanceTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_payment_sync_reduces_order_receivable_balance_and_marks_partial_then_paid(): void
    {
        $customer = $this->createCustomerCompany('Tahsilat Müşteri');
        $order = $this->createOrder($customer, 'SP-PAYMENT-001', 18000);
        $account = $this->ensureCustomerCurrentAccount($customer);

        $this->syncOrderDebit($order);
        $this->createCollectionPayment($order, 5000);

        $summaryService = app(CurrentAccountBalanceSummaryService::class);
        $summary = $summaryService->summarizeAccounts($this->tenant->id, [$account->id])[$account->id];
        $debit = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', OrderCurrentAccountDebitSyncService::SOURCE_TYPE)
            ->where('source_id', $order->id)
            ->firstOrFail();
        $payment = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', 'order_payment')
            ->firstOrFail();

        $this->assertSame(CurrentAccountTransaction::STATUS_PARTIALLY_PAID, $debit->status);
        $this->assertSame(CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT, $payment->transaction_type);
        $this->assertSame('receivable', $summary['balance_direction']);
        $this->assertSame(13000.0, (float) $summary['balance']);

        $this->createCollectionPayment($order->fresh(['customer.companyRoles', 'payments']), 13000);

        $afterPaid = $summaryService->summarizeAccounts($this->tenant->id, [$account->id])[$account->id];
        $debit->refresh();

        $this->assertSame(CurrentAccountTransaction::STATUS_PAID, $debit->status);
        $this->assertSame('closed', $afterPaid['balance_direction']);
        $this->assertSame(0.0, (float) $afterPaid['balance']);
    }
}
