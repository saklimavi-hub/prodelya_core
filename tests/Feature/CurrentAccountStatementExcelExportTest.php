<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStatementExportFixtures;
use Tests\TestCase;

class CurrentAccountStatementExcelExportTest extends TestCase
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

    public function test_finance_user_can_export_excel_compatible_csv_with_filters(): void
    {
        $financeUser = $this->makeFinanceUser($this->tenant, 'statement-excel-export@example.test');
        [$account] = $this->createLinkedCompanyAccount($this->tenant, 'Excel Export Cari');

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 820,
            'currency' => 'TL',
            'transaction_date' => '2026-07-05',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'CSV filtre satış hareketi',
        ]);

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
            'direction' => CurrentAccountTransaction::DIRECTION_CREDIT,
            'amount' => 200,
            'currency' => 'TL',
            'transaction_date' => '2026-08-05',
            'status' => CurrentAccountTransaction::STATUS_CLOSED,
            'description' => 'Filtre dışı tahsilat hareketi',
            'meta_json' => ['secret' => 'do-not-show'],
        ]);

        $response = $this->actingAs($financeUser, 'web')
            ->get($this->tenantUrl($this->tenant, '/admin/current-accounts/' . $account->id . '/transactions/export/excel?from=2026-07-01&to=2026-07-31&transaction_type=customer_debit&status=open&search=CSV&mode=summary'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));

        $content = $response->streamedContent();

        $this->assertSame("\xEF\xBB\xBF", substr($content, 0, 3));
        $this->assertStringContainsString('Excel Export Cari', $content);
        $this->assertStringContainsString('Borç', $content);
        $this->assertStringContainsString('Alacak', $content);
        $this->assertStringContainsString('Bakiye', $content);
        $this->assertStringContainsString('CSV filtre satış hareketi', $content);
        $this->assertStringContainsString('820,00 TL', $content);
        $this->assertStringNotContainsString('+820,00 TL', $content);
        $this->assertStringNotContainsString('Filtre dışı tahsilat hareketi', $content);
        $this->assertStringNotContainsString('Bize Borçlu', $content);
        $this->assertStringNotContainsString('Biz Borçluyuz', $content);
        $this->assertStringNotContainsString('meta_json', $content);
        $this->assertStringNotContainsString('tenant_id', $content);
        $this->assertStringNotContainsString('secret', $content);
    }
}
