<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Services\OrderPaymentCurrentAccountSyncService;
use App\Services\SubcontractorProductionCurrentAccountSyncService;
use App\Services\SupplierProcurementCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class StatementActionSourceMatrixTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_statement_actions_follow_source_matrix(): void
    {
        $customer = $this->createCustomerCompany('Action Matrix Müşteri');
        $order = $this->createOrder($customer, 'SP-MATRIX-001', 9900);
        $debit = $this->syncOrderDebit($order);
        $account = $debit->fresh()->currentAccount;

        $this->createCollectionPayment($order->fresh(['customer.companyRoles', 'payments']), 1900);

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_ADJUSTMENT,
            'source_type' => CurrentAccountTransaction::SOURCE_TYPE_MANUAL,
            'source_id' => null,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 300,
            'currency' => 'TRY',
            'transaction_date' => '2026-07-03',
            'description' => 'Matrix manuel hareket',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            'source_type' => SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE,
            'source_id' => 9981,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 500,
            'currency' => 'TRY',
            'transaction_date' => '2026-07-03',
            'description' => 'Matrix tedarik hareketi',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUBCONTRACTOR_DEBIT,
            'source_type' => SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE,
            'source_id' => 9982,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 700,
            'currency' => 'TRY',
            'transaction_date' => '2026-07-03',
            'description' => 'Matrix fason hareketi',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
            'source_type' => CurrentAccountTransaction::SOURCE_TYPE_MANUAL,
            'source_id' => null,
            'direction' => CurrentAccountTransaction::DIRECTION_CREDIT,
            'amount' => 120,
            'currency' => 'TRY',
            'transaction_date' => '2026-07-03',
            'description' => 'Matrix cancelled manual',
            'status' => CurrentAccountTransaction::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'))
            ->assertOk()
            ->assertSee('Sipariş kaynaklı')
            ->assertSee('Siparişi Aç')
            ->assertSee('Tahsilatı Aç')
            ->assertSee('İptal Et')
            ->assertSee('Tedarik kaynaklı')
            ->assertSee('Fason / Üretim kaynaklı')
            ->assertSee('İptal edildi');
    }
}
