<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Services\CurrentAccountBalanceSummaryService;
use App\Services\CurrentAccountTransactionService;
use App\Services\SupplierProcurementCurrentAccountSyncService;
use App\Services\SupplierProcurementRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsCounterpartyCurrentAccountFixtures;
use Tests\TestCase;

class SupplierProcurementCurrentAccountSyncTest extends TestCase
{
    use BuildsCounterpartyCurrentAccountFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCounterpartyFixtures();
    }

    public function test_supplier_procurement_sync_is_idempotent_and_supplier_payment_reduces_payable_balance(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('SPHARD-A');
        $procurement = $this->createSupplierProcurement($supplier, $source, 'SP-SUP-HARD-001');

        $request = app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $supplier->id,
            [$procurement->id],
            $this->adminUser
        );

        $item = $request->items->firstOrFail();
        $request = app(SupplierProcurementRequestService::class)->updateRequestItems($request, [[
            'id' => $item->id,
            'included' => 1,
            'requested_quantity' => 100,
            'purchase_list_price' => 10.00,
            'discount_rate' => 20,
        ]], $this->adminUser);

        $item = $request->items->firstOrFail();
        $transaction = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $item->id)
            ->where('transaction_type', CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT)
            ->firstOrFail();

        $this->assertSame('800.00', (string) $transaction->amount);

        app(SupplierProcurementCurrentAccountSyncService::class)->syncRequestItem($item->fresh(['request.supplier.tenants', 'procurement', 'order']));

        $this->assertSame(1, CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $item->id)
            ->where('transaction_type', CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT)
            ->count());

        $account = $transaction->currentAccount()->firstOrFail();

        app(CurrentAccountTransactionService::class)->createManualTransaction($account, [
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_PAYMENT,
            'amount' => 300,
            'currency' => 'TL',
            'transaction_date' => '2026-07-05',
            'status' => CurrentAccountTransaction::STATUS_CLOSED,
            'description' => 'Tedarikçi ödemesi',
        ], $this->financeUser);

        $summary = app(CurrentAccountBalanceSummaryService::class)->summarizeAccounts($this->tenant->id, [$account->id])[$account->id];

        $this->assertSame('payable', $summary['balance_direction']);
        $this->assertSame(-500.0, (float) $summary['balance']);
        $this->assertSame(500.0, (float) $summary['balance_amount']);
        $this->assertSame('Alacak Bakiyesi', $summary['balance_direction_label']);
    }
}
