<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use App\Services\CurrentAccountStatementExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStatementExportFixtures;
use Tests\TestCase;

class CurrentAccountStatementPdfExportTest extends TestCase
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

    public function test_finance_user_can_export_filtered_statement_as_pdf_without_sensitive_fields(): void
    {
        $financeUser = $this->makeFinanceUser($this->tenant, 'statement-pdf-export@example.test');
        [$account] = $this->createLinkedCompanyAccount($this->tenant, 'PDF Export Cari');

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 1750,
            'currency' => 'TL',
            'transaction_date' => '2026-07-02',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'PDF filtre satış hareketi',
            'meta_json' => ['raw' => 'secret', 'token' => 'hidden-value'],
        ]);

        $response = $this->actingAs($financeUser, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/current-accounts/' . $account->id . '/transactions/export/pdf?from=2026-07-01&to=2026-07-31&transaction_type=customer_debit&status=open&mode=summary'));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $html = app(CurrentAccountStatementExportService::class)->renderPdfHtml($account->fresh(['roles', 'primaryCompanyLink']), [
            'from' => '2026-07-01',
            'to' => '2026-07-31',
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'status' => 'open',
        ], 'summary');

        $this->assertStringContainsString('PDF Export Cari', $html);
        $this->assertStringContainsString('İŞLEM DÖKÜMÜ', $html);
        $this->assertStringContainsString('İşlem Tarihi', $html);
        $this->assertStringContainsString('Toplam Borç', $html);
        $this->assertStringContainsString('1.750,00 TL', $html);
        $this->assertStringNotContainsString('+1.750,00 TL', $html);
        $this->assertStringNotContainsString('Bize Borçlu', $html);
        $this->assertStringNotContainsString('Biz Borçluyuz', $html);
        $this->assertStringNotContainsString('raw', $html);
        $this->assertStringNotContainsString('token', $html);
        $this->assertStringNotContainsString('tenant_id', $html);
    }
}
