<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use App\Services\CurrentAccountStatementExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStatementExportFixtures;
use Tests\TestCase;

class StatementExportTerminologyTest extends TestCase
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

    public function test_pdf_and_csv_exports_use_borc_alacak_bakiye_language(): void
    {
        $financeUser = $this->makeFinanceUser($this->tenant, 'statement-export-terminology@example.test');
        [$account] = $this->createLinkedCompanyAccount($this->tenant, 'Export Terminoloji Cari');

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 1500,
            'currency' => 'TL',
            'transaction_date' => '2026-07-03',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Export satış',
        ]);

        $html = app(CurrentAccountStatementExportService::class)->renderPdfHtml(
            $account->fresh(['roles', 'primaryCompanyLink']),
            [],
            'summary'
        );

        $csv = $this->actingAs($financeUser, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/current-accounts/' . $account->id . '/transactions/export/excel?mode=summary'))
            ->streamedContent();

        $this->assertStringContainsString('Toplam Borç', $html);
        $this->assertStringContainsString('Toplam Alacak', $html);
        $this->assertStringContainsString('Bakiye', $html);
        $this->assertStringContainsString('1.500,00 TL', $html);
        $this->assertStringNotContainsString('+1.500,00 TL', $html);
        $this->assertStringNotContainsString('Bize Borçlu', $html);
        $this->assertStringNotContainsString('Biz Borçluyuz', $html);

        $this->assertStringContainsString('Borç', $csv);
        $this->assertStringContainsString('Alacak', $csv);
        $this->assertStringContainsString('Bakiye', $csv);
        $this->assertStringContainsString('1.500,00 TL', $csv);
        $this->assertStringNotContainsString('+1.500,00 TL', $csv);
        $this->assertStringNotContainsString('Bize Borçlu', $csv);
        $this->assertStringNotContainsString('Biz Borçluyuz', $csv);
    }
}
