<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStatementExportFixtures;
use Tests\TestCase;

class CurrentAccountLedgerTerminologyTest extends TestCase
{
    use BuildsStatementExportFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->enableCurrentAccounts($this->tenant);
    }

    public function test_statement_screen_uses_borc_alacak_bakiye_language_without_old_phrases(): void
    {
        $financeUser = $this->makeFinanceUser($this->tenant, 'ledger-terminology@example.test');
        [$account] = $this->createLinkedCompanyAccount($this->tenant, 'Ledger Terminoloji Cari');

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 1000,
            'currency' => 'TL',
            'transaction_date' => '2026-07-01',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Ledger satış',
        ]);

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
            'direction' => CurrentAccountTransaction::DIRECTION_CREDIT,
            'amount' => 250,
            'currency' => 'TL',
            'transaction_date' => '2026-07-02',
            'status' => CurrentAccountTransaction::STATUS_CLOSED,
            'description' => 'Ledger tahsilat',
        ]);

        $this->actingAs($financeUser, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/current-accounts/' . $account->id . '/transactions?from=2026-07-02'))
            ->assertOk()
            ->assertSee('Borç')
            ->assertSee('Alacak')
            ->assertSee('Bakiye')
            ->assertSee('Önceden Devreden')
            ->assertSee('Toplam Borç')
            ->assertSee('Toplam Alacak')
            ->assertDontSee('Bize Borçlu')
            ->assertDontSee('Biz Borçluyuz');
    }
}
