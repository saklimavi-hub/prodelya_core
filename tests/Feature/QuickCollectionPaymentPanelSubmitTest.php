<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use App\Services\CurrentAccountTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickCollectionPaymentPanelSubmitTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $financeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->financeUser = $this->createFinanceUser();
    }

    public function test_quick_panel_can_create_collection_and_payment_and_redirect_back(): void
    {
        [$account, $company] = $this->createLinkedCompanyAccount('Panel Tahsilat Ödeme Cari', [
            CurrentAccountRole::ROLE_CUSTOMER,
            CurrentAccountRole::ROLE_SUPPLIER,
        ]);

        app(CurrentAccountTransactionService::class)->createManualTransaction($account, [
            'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
            'amount' => 1000,
            'currency' => 'TL',
            'transaction_date' => '2026-07-04',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Açılış borcu',
        ], $this->financeUser);

        $returnUrl = $this->tenantUrl('/admin/companies/' . $company->id);

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'), [
                'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_PAYMENT,
                'amount' => 300,
                'currency' => 'TL',
                'transaction_date' => '2026-07-04',
                'status' => CurrentAccountTransaction::STATUS_CLOSED,
                'description' => 'Panel tahsilatı',
                'redirect_to' => $returnUrl,
            ])
            ->assertRedirect($returnUrl);

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'), [
                'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_PAYMENT,
                'amount' => 125,
                'currency' => 'TL',
                'transaction_date' => '2026-07-04',
                'status' => CurrentAccountTransaction::STATUS_CLOSED,
                'description' => 'Panel ödemesi',
                'redirect_to' => $returnUrl,
                'submit_action' => 'save_and_new',
            ])
            ->assertRedirect($returnUrl . '?quick_panel=1&form_type=supplier_payment#hizli-islem-paneli');

        $statement = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'));

        $statement->assertOk()
            ->assertSee('Panel tahsilatı')
            ->assertSee('Panel ödemesi');

        $summary = app(CurrentAccountTransactionService::class)->getAccountSummary($account->fresh());
        $this->assertSame(575.0, $summary['currencies']['TL']['balance']);
    }

    private function createLinkedCompanyAccount(string $displayName, array $roles): array
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, $roles);
        $company = app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, $roles);

        return [$account->fresh(['roles', 'links']), Company::query()->findOrFail($company->id)];
    }

    private function createFinanceUser(): User
    {
        $user = User::query()->create([
            'name' => 'Quick Panel Finance',
            'email' => 'quick-panel-finance@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        foreach (['tenant_owner', 'finance'] as $roleKey) {
            UserRole::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'user_id' => $user->id,
                'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id,
            ]);
        }

        return $user;
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
