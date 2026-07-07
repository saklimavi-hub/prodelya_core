<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\OrderItemPrintProduction;
use App\Services\CurrentAccountBalanceSummaryService;
use App\Services\CurrentAccountTransactionService;
use App\Services\SubcontractorProductionCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCounterpartyCurrentAccountFixtures;
use Tests\TestCase;

class SubcontractorProductionCurrentAccountSyncTest extends TestCase
{
    use BuildsCounterpartyCurrentAccountFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCounterpartyFixtures();
    }

    public function test_subcontractor_production_sync_is_idempotent_and_payment_reduces_payable_balance(): void
    {
        $partner = $this->createPartnerCompany('Hardening Fason Partner');
        $production = $this->createProduction('SP-SUB-HARD-001', $partner, OrderItemPrintProduction::TYPE_OUTSOURCED);
        $production->forceFill([
            'subcontractor_cost' => 1250,
            'subcontractor_cost_currency' => 'TL',
            'updated_by' => $this->adminUser->id,
        ])->save();

        $transaction = app(SubcontractorProductionCurrentAccountSyncService::class)->syncProduction($production->fresh([
            'order.customer',
            'orderItem',
            'orderItemPrint',
            'productionCompany.companyRoles',
        ]));

        $this->assertNotNull($transaction);

        app(SubcontractorProductionCurrentAccountSyncService::class)->syncProduction($production->fresh([
            'order.customer',
            'orderItem',
            'orderItemPrint',
            'productionCompany.companyRoles',
        ]));

        $this->assertSame(1, CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $production->id)
            ->where('transaction_type', CurrentAccountTransaction::TYPE_SUBCONTRACTOR_DEBIT)
            ->count());

        $account = $transaction->currentAccount()->firstOrFail();

        app(CurrentAccountTransactionService::class)->createManualTransaction($account, [
            'transaction_type' => CurrentAccountTransaction::TYPE_SUBCONTRACTOR_PAYMENT,
            'amount' => 250,
            'currency' => 'TL',
            'transaction_date' => '2026-07-05',
            'status' => CurrentAccountTransaction::STATUS_CLOSED,
            'description' => 'Fason ödemesi',
        ], $this->financeUser);

        $summary = app(CurrentAccountBalanceSummaryService::class)->summarizeAccounts($this->tenant->id, [$account->id])[$account->id];

        $this->assertSame('payable', $summary['balance_direction']);
        $this->assertSame(-1000.0, (float) $summary['balance']);
        $this->assertSame(1000.0, (float) $summary['balance_amount']);
        $this->assertSame('Alacak Bakiyesi', $summary['balance_direction_label']);
    }
}
