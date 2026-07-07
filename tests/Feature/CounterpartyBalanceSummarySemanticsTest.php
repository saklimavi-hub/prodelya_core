<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Services\CurrentAccountBalanceSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCounterpartyCurrentAccountFixtures;
use Tests\TestCase;

class CounterpartyBalanceSummarySemanticsTest extends TestCase
{
    use BuildsCounterpartyCurrentAccountFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCounterpartyFixtures();
    }

    public function test_counterparty_debit_and_payment_types_are_read_as_payables_and_cancelled_rows_are_excluded(): void
    {
        $supplier = $this->createCurrentAccountWithRole('Semantics Supplier', 'supplier');
        $subcontractor = $this->createCurrentAccountWithRole('Semantics Subcontractor', 'subcontractor');
        $carrier = $this->createCurrentAccountWithRole('Semantics Carrier', 'carrier');

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $supplier->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 1000,
            'currency' => 'TL',
            'transaction_date' => '2026-07-01',
            'due_date' => now()->subDays(5)->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Supplier debit',
        ]);
        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $supplier->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_PAYMENT,
            'direction' => CurrentAccountTransaction::DIRECTION_CREDIT,
            'amount' => 400,
            'currency' => 'TL',
            'transaction_date' => '2026-07-02',
            'status' => CurrentAccountTransaction::STATUS_CLOSED,
            'description' => 'Supplier payment',
        ]);
        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $subcontractor->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUBCONTRACTOR_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 900,
            'currency' => 'TL',
            'transaction_date' => '2026-07-01',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Subcontractor debit',
        ]);
        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $subcontractor->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUBCONTRACTOR_PAYMENT,
            'direction' => CurrentAccountTransaction::DIRECTION_CREDIT,
            'amount' => 150,
            'currency' => 'TL',
            'transaction_date' => '2026-07-02',
            'status' => CurrentAccountTransaction::STATUS_CLOSED,
            'description' => 'Subcontractor payment',
        ]);
        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $carrier->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CARRIER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 300,
            'currency' => 'TL',
            'transaction_date' => '2026-07-01',
            'due_date' => now()->subDays(2)->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Carrier debit',
        ]);
        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $carrier->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CARRIER_PAYMENT,
            'direction' => CurrentAccountTransaction::DIRECTION_CREDIT,
            'amount' => 100,
            'currency' => 'TL',
            'transaction_date' => '2026-07-02',
            'status' => CurrentAccountTransaction::STATUS_CLOSED,
            'description' => 'Carrier payment',
        ]);
        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $carrier->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CARRIER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 999,
            'currency' => 'TL',
            'transaction_date' => '2026-07-03',
            'status' => CurrentAccountTransaction::STATUS_CANCELLED,
            'description' => 'Cancelled carrier debit',
        ]);

        $summaries = app(CurrentAccountBalanceSummaryService::class)->summarizeAccounts($this->tenant->id, [$supplier->id, $subcontractor->id, $carrier->id]);

        $this->assertSame('payable', $summaries[$supplier->id]['balance_direction']);
        $this->assertSame(-600.0, (float) $summaries[$supplier->id]['balance']);
        $this->assertSame(600.0, (float) $summaries[$supplier->id]['balance_amount']);
        $this->assertSame('payable', $summaries[$subcontractor->id]['balance_direction']);
        $this->assertSame(-750.0, (float) $summaries[$subcontractor->id]['balance']);
        $this->assertSame(750.0, (float) $summaries[$subcontractor->id]['balance_amount']);
        $this->assertSame('payable', $summaries[$carrier->id]['balance_direction']);
        $this->assertSame(-200.0, (float) $summaries[$carrier->id]['balance']);
        $this->assertSame(200.0, (float) $summaries[$carrier->id]['balance_amount']);
        $this->assertSame(1, (int) $summaries[$carrier->id]['overdue_transaction_count']);
    }
}
