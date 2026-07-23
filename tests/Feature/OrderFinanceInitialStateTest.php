<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Services\FinanceSummaryService;
use App\Services\OrderCurrentAccountDebitSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLiveB1QuoteOrderFixtures;
use Tests\TestCase;

class OrderFinanceInitialStateTest extends TestCase
{
    use BuildsLiveB1QuoteOrderFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLiveB1Fixtures();
    }

    public function test_conversion_creates_single_customer_debit_and_hides_finance_for_operations_user(): void
    {
        $order = $this->convertQuote($this->createQuoteViaHttp(['document_number' => 'TK-B1-FIN-001', 'with_print' => true]))->fresh(['payments', 'customer.companyRoles']);

        $transaction = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $order->tenant_account_id)
            ->where('source_type', OrderCurrentAccountDebitSyncService::SOURCE_TYPE)
            ->where('source_id', $order->id)
            ->where('transaction_type', CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT)
            ->firstOrFail();

        $summary = app(FinanceSummaryService::class)->summarizeOrder($order);
        $this->assertEquals((float) $order->grand_total, (float) $transaction->amount);
        $this->assertEquals(0.0, (float) ($summary['net_paid_total'] ?? 0));
        $this->assertEquals((float) $order->grand_total, (float) ($summary['balance_due'] ?? 0));

        $graphicUser = $this->createUserWithRole('graphic', 'graphic.liveb1.finance@prodelya.local');
        $response = $this->actingAs($graphicUser)
            ->withServerVariables(['HTTP_HOST' => $this->centralHost])
            ->get(route('admin.orders.index'));

        $response->assertDontSee(route('admin.finance.show', $order), false);
        $response->assertDontSee('Ödeme Bekleyen');
    }
}
