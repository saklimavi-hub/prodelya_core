<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use App\Services\CurrentAccountBalanceSummaryService;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentAccountSignedBalanceDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_signed_balance_display_uses_minus_and_closed_labels_without_positive_plus(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();

        $receivable = CurrentAccount::query()->create([
            'tenant_account_id' => $tenant->id,
            'display_name' => 'Signed Receivable',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);
        app(CurrentAccountSyncService::class)->syncRoles($receivable, [CurrentAccountRole::ROLE_CUSTOMER]);

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $receivable->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 900,
            'currency' => 'TL',
            'transaction_date' => now()->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        $payable = CurrentAccount::query()->create([
            'tenant_account_id' => $tenant->id,
            'display_name' => 'Signed Payable',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);
        app(CurrentAccountSyncService::class)->syncRoles($payable, [CurrentAccountRole::ROLE_SUPPLIER]);

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $payable->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 400,
            'currency' => 'TL',
            'transaction_date' => now()->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        $closed = CurrentAccount::query()->create([
            'tenant_account_id' => $tenant->id,
            'display_name' => 'Signed Closed',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);
        app(CurrentAccountSyncService::class)->syncRoles($closed, [CurrentAccountRole::ROLE_CUSTOMER]);

        $summary = app(CurrentAccountBalanceSummaryService::class)->summarizeAccounts($tenant->id, [
            $receivable->id,
            $payable->id,
            $closed->id,
        ]);

        $this->assertSame('900,00 TL', $summary[$receivable->id]['formatted_balance']);
        $this->assertSame('Borç Bakiyesi', $summary[$receivable->id]['balance_direction_label']);
        $this->assertSame('-400,00 TL', $summary[$payable->id]['formatted_balance']);
        $this->assertSame('Alacak Bakiyesi', $summary[$payable->id]['balance_direction_label']);
        $this->assertSame('0,00 TL', $summary[$closed->id]['formatted_balance']);
        $this->assertSame('Kapalı', $summary[$closed->id]['balance_direction_label']);
    }
}
