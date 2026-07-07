<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Services\OrderPaymentCurrentAccountSyncService;
use App\Services\SubcontractorProductionCurrentAccountSyncService;
use App\Services\SupplierProcurementCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderCustomerDebitFixtures;
use Tests\TestCase;

class CariStatementActionMatrixUiTest extends TestCase
{
    use BuildsOrderCustomerDebitFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderCustomerDebitFixtures();
    }

    public function test_statement_action_matrix_is_preserved_on_ui(): void
    {
        $customer = $this->createCustomerCompany('Aksiyon Matrisi Cari');
        $order = $this->createOrder($customer, 'SP-AKSIYON-001', 9900);
        $debit = $this->syncOrderDebit($order);
        $account = $debit->fresh()->currentAccount;
        $this->createCollectionPayment($order->fresh(['customer.companyRoles', 'payments']), 1900);
        $paymentId = $order->fresh()->payments()->latest('id')->value('id');
        $paymentTransaction = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', OrderPaymentCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $paymentId)
            ->firstOrFail();

        $manual = CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_ADJUSTMENT,
            'source_type' => CurrentAccountTransaction::SOURCE_TYPE_MANUAL,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 300,
            'currency' => 'TRY',
            'transaction_date' => '2026-07-03',
            'description' => 'Manuel aksiyon hareketi',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            'source_type' => SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE,
            'source_id' => 551,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 500,
            'currency' => 'TRY',
            'transaction_date' => '2026-07-03',
            'description' => 'Tedarik kaynaklı hareket',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUBCONTRACTOR_DEBIT,
            'source_type' => SubcontractorProductionCurrentAccountSyncService::SOURCE_TYPE,
            'source_id' => 552,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 500,
            'currency' => 'TRY',
            'transaction_date' => '2026-07-03',
            'description' => 'Fason kaynaklı hareket',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        $response = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'));

        $response->assertOk()
            ->assertSee('Sipariş kaynaklı')
            ->assertSee('Siparişi Aç')
            ->assertSee('Tahsilatı Aç')
            ->assertSee('Tedarik kaynaklı')
            ->assertSee('Fason / Üretim kaynaklı')
            ->assertSee('Bu cari hareketi iptal etmek üzeresiniz. İşlem bakiyeyi etkiler. Devam etmek istiyor musunuz?')
            ->assertDontSee('current-account-transactions/' . $debit->id . '/cancel', false)
            ->assertDontSee('current-account-transactions/' . $paymentTransaction->id . '/cancel', false)
            ->assertSee('current-account-transactions/' . $manual->id . '/cancel', false);
    }
}
