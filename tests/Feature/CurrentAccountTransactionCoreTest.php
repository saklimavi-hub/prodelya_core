<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\CurrentAccountSyncService;
use App\Services\CurrentAccountTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CurrentAccountTransactionCoreTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $adminUser;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
    }

    public function test_migration_model_summary_and_cancel_flow_work_safely(): void
    {
        $this->assertTrue(Schema::hasTable('current_account_transactions'));
        $this->assertTrue(Schema::hasColumns('current_account_transactions', [
            'tenant_account_id',
            'current_account_id',
            'transaction_type',
            'direction',
            'amount',
            'currency',
            'transaction_date',
            'status',
            'cancelled_at',
            'cancelled_by',
            'cancellation_reason',
            'meta_json',
        ]));

        $account = $this->createAccount('Core Transaction Cari', [CurrentAccountRole::ROLE_CUSTOMER]);
        $service = app(CurrentAccountTransactionService::class);

        $debit = $service->createManualTransaction($account, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 1000,
            'currency' => 'TRY',
            'transaction_date' => '2026-06-16',
            'description' => 'Müşteri borç kaydı',
        ], $this->adminUser);

        $credit = $service->createManualTransaction($account, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
            'direction' => CurrentAccountTransaction::DIRECTION_CREDIT,
            'amount' => 300,
            'currency' => 'TRY',
            'transaction_date' => '2026-06-16',
            'description' => 'Tahsilat kaydı',
        ], $this->adminUser);

        $usdDebit = $service->createManualTransaction($account, [
            'transaction_type' => CurrentAccountTransaction::TYPE_OPENING_BALANCE,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 120,
            'currency' => 'USD',
            'transaction_date' => '2026-06-16',
            'description' => 'USD açılış',
        ], $this->adminUser);

        $this->assertInstanceOf(CurrentAccountTransaction::class, $debit);
        $this->assertTrue($debit->isDebit());
        $this->assertTrue($credit->isCredit());
        $this->assertSame('1.000,00 TRY', $debit->formattedAmount());
        $this->assertSame('manual', $debit->source_type);

        $summary = $service->getAccountSummary($account->fresh());
        $this->assertSame(1000.0, $summary['currencies']['TRY']['debit_total']);
        $this->assertSame(300.0, $summary['currencies']['TRY']['credit_total']);
        $this->assertSame(700.0, $summary['currencies']['TRY']['balance']);
        $this->assertSame(120.0, $summary['currencies']['USD']['debit_total']);
        $this->assertSame(0.0, $summary['currencies']['USD']['credit_total']);
        $this->assertSame(120.0, $summary['currencies']['USD']['balance']);

        $service->cancelTransaction($credit, 'Yanlış tahsilat', $this->adminUser);

        $cancelled = $credit->fresh();
        $this->assertTrue($cancelled->isCancelled());
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame('Yanlış tahsilat', $cancelled->cancellation_reason);

        $summaryAfterCancel = $service->getAccountSummary($account->fresh());
        $this->assertSame(1000.0, $summaryAfterCancel['currencies']['TRY']['debit_total']);
        $this->assertSame(0.0, $summaryAfterCancel['currencies']['TRY']['credit_total']);
        $this->assertSame(1000.0, $summaryAfterCancel['currencies']['TRY']['balance']);

        $this->expectException(ValidationException::class);
        $service->createManualTransaction($account, [
            'transaction_type' => CurrentAccountTransaction::TYPE_ADJUSTMENT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => -10,
            'currency' => 'TRY',
        ], $this->adminUser);
    }

    public function test_blocked_archived_cancel_validation_and_tenant_scope_guards_work(): void
    {
        $service = app(CurrentAccountTransactionService::class);

        $blocked = $this->createAccount('Blocked Cari', [CurrentAccountRole::ROLE_CUSTOMER], CurrentAccount::STATUS_BLOCKED);
        $archived = $this->createAccount('Archived Cari', [CurrentAccountRole::ROLE_CUSTOMER], CurrentAccount::STATUS_ARCHIVED);

        foreach ([$blocked, $archived] as $account) {
            try {
                $service->createManualTransaction($account, [
                    'transaction_type' => CurrentAccountTransaction::TYPE_ADJUSTMENT,
                    'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
                    'amount' => 10,
                    'currency' => 'TRY',
                ], $this->adminUser);

                $this->fail('ValidationException bekleniyordu.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('account', $exception->errors());
            }
        }

        $account = $this->createAccount('Cancel Validation Cari', [CurrentAccountRole::ROLE_CUSTOMER]);
        $transaction = $service->createManualTransaction($account, [
            'transaction_type' => CurrentAccountTransaction::TYPE_ADJUSTMENT,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 25,
            'currency' => 'TRY',
        ], $this->adminUser);

        $this->expectException(ValidationException::class);
        $service->cancelTransaction($transaction, '', $this->adminUser);
    }

    public function test_transaction_tenant_scope_guard_rejects_foreign_transaction(): void
    {
        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant Transactions',
            'legal_name' => 'Other Tenant Transactions Ltd.',
            'slug' => 'other-tenant-transactions',
            'panel_subdomain' => 'other-tenant-transactions',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $foreignAccount = CurrentAccount::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'display_name' => 'Foreign Tx Account',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        $transaction = CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'current_account_id' => $foreignAccount->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_OTHER,
            'source_type' => 'manual',
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 50,
            'currency' => 'TRY',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        $service = app(CurrentAccountTransactionService::class);

        try {
            $service->assertTenantScope($foreignAccount, $this->tenant->id);
            $this->fail('Tenant scope guard 403 vermeliydi.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        try {
            $service->assertTransactionTenantScope($transaction, $this->tenant->id);
            $this->fail('Transaction tenant scope guard 403 vermeliydi.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function createAccount(string $displayName, array $roles, string $status = CurrentAccount::STATUS_ACTIVE): CurrentAccount
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => $status,
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, $roles);

        return $account->fresh(['roles']);
    }
}
