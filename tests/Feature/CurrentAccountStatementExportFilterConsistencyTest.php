<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStatementExportFixtures;
use Tests\TestCase;

class CurrentAccountStatementExportFilterConsistencyTest extends TestCase
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

    public function test_screen_filters_and_export_filters_return_same_visible_transactions(): void
    {
        $financeUser = $this->makeFinanceUser($this->tenant, 'statement-export-filter-consistency@example.test');
        [$account] = $this->createLinkedCompanyAccount($this->tenant, 'Filter Consistency Cari');

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 500,
            'currency' => 'TL',
            'transaction_date' => '2026-07-03',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'FILTRE-ICI satış hareketi',
        ]);

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
            'direction' => CurrentAccountTransaction::DIRECTION_CREDIT,
            'amount' => 120,
            'currency' => 'TL',
            'transaction_date' => '2026-07-10',
            'status' => CurrentAccountTransaction::STATUS_CLOSED,
            'description' => 'FILTRE-DISI tahsilat hareketi',
        ]);

        $query = '?from=2026-07-01&to=2026-07-31&transaction_type=customer_debit&status=open&search=FILTRE-ICI';

        $screen = $this->actingAs($financeUser, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/current-accounts/' . $account->id . '/transactions' . $query));

        $screen->assertOk()
            ->assertSee('FILTRE-ICI satış hareketi')
            ->assertDontSee('FILTRE-DISI tahsilat hareketi');

        $export = $this->actingAs($financeUser, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/current-accounts/' . $account->id . '/transactions/export/excel' . $query . '&mode=summary'));

        $content = $export->streamedContent();

        $this->assertStringContainsString('FILTRE-ICI satış hareketi', $content);
        $this->assertStringNotContainsString('FILTRE-DISI tahsilat hareketi', $content);
    }
}
