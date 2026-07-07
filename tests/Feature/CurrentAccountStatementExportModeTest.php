<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use App\Services\CurrentAccountStatementExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsStatementExportFixtures;
use Tests\TestCase;

class CurrentAccountStatementExportModeTest extends TestCase
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

    public function test_summary_and_detailed_modes_render_expected_safe_content(): void
    {
        [$account] = $this->createLinkedCompanyAccount($this->tenant, 'Mode Export Cari');

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 640,
            'currency' => 'TL',
            'transaction_date' => '2026-07-04',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Detaylı mod satış',
            'meta_json' => [
                'manual' => [
                    'internal_note' => '<script>alert(1)</script> gizli not',
                    'document_number' => 'MD-001',
                ],
            ],
        ]);

        $service = app(CurrentAccountStatementExportService::class);
        $summaryHtml = $service->renderPdfHtml($account->fresh(['roles', 'primaryCompanyLink']), [], 'summary');
        $detailedHtml = $service->renderPdfHtml($account->fresh(['roles', 'primaryCompanyLink']), [], 'detailed');

        $this->assertStringContainsString('Genel Ekstre', $summaryHtml);
        $this->assertStringContainsString('İŞLEM DÖKÜMÜ', $summaryHtml);
        $this->assertStringNotContainsString('Vergi No', $summaryHtml);
        $this->assertStringNotContainsString('gizli not', $summaryHtml);
        $this->assertStringNotContainsString('meta_json', $summaryHtml);

        $this->assertStringContainsString('Detaylı Ekstre', $detailedHtml);
        $this->assertStringContainsString('Vergi No', $detailedHtml);
        $this->assertStringContainsString('Telefon / WhatsApp', $detailedHtml);
        $this->assertStringContainsString('Vade Yaşlandırma', $detailedHtml);
        $this->assertStringNotContainsString('Bize Borçlu', $detailedHtml);
        $this->assertStringNotContainsString('Biz Borçluyuz', $detailedHtml);
        $this->assertStringNotContainsString('<script>', $detailedHtml);
        $this->assertStringNotContainsString('gizli not', $detailedHtml);
    }
}
