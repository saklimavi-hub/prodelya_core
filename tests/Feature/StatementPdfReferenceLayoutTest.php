<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use App\Services\CurrentAccountStatementExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStatementExportFixtures;
use Tests\TestCase;

class StatementPdfReferenceLayoutTest extends TestCase
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

    public function test_pdf_layout_matches_reference_statement_logic(): void
    {
        [$account] = $this->createLinkedCompanyAccount($this->tenant, 'PDF Referans Cari');

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 700,
            'currency' => 'TL',
            'transaction_date' => '2026-07-03',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'PDF referans satış',
        ]);

        $html = app(CurrentAccountStatementExportService::class)->renderPdfHtml(
            $account->fresh(['roles', 'primaryCompanyLink']),
            ['from' => '2026-07-01', 'to' => '2026-07-31'],
            'detailed'
        );

        $this->assertStringContainsString('İŞLEM DÖKÜMÜ', $html);
        $this->assertStringContainsString('İşlem Tarihi', $html);
        $this->assertStringContainsString('Açıklama', $html);
        $this->assertStringContainsString('Vade Tarihi', $html);
        $this->assertStringContainsString('Borç', $html);
        $this->assertStringContainsString('Alacak', $html);
        $this->assertStringContainsString('Bakiye ₺', $html);
        $this->assertStringContainsString('Önceden Devreden', $html);
        $this->assertStringContainsString('Toplam Alacak', $html);
        $this->assertStringContainsString('Toplam Borç', $html);
        $this->assertStringContainsString('Bakiye', $html);
    }
}
