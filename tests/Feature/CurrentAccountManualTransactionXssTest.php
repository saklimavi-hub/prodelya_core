<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentAccountManualTransactionXssTest extends TestCase
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

    public function test_manual_transaction_fields_are_rendered_safely_and_meta_json_is_not_dumped(): void
    {
        $account = $this->createAccount('Xss Cari');

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'), [
                'transaction_type' => CurrentAccountTransaction::TYPE_CUSTOMER_DEBIT,
                'amount' => 150,
                'currency' => 'TL',
                'transaction_date' => '2026-07-03',
                'status' => CurrentAccountTransaction::STATUS_OPEN,
                'document_number' => '<b>DOC-<script>1</script></b>',
                'description' => '<script>alert(1)</script> Güvenli açıklama',
                'internal_note' => '<img src=x onerror=alert(2)> İç not',
                'payment_method' => 'nakit',
            ])
            ->assertRedirect($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'));

        $response = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'));

        $response->assertOk()
            ->assertSee('alert(1) Güvenli açıklama')
            ->assertSee('Belge: DOC-1')
            ->assertDontSee('<b>DOC-<script>1</script></b>', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertDontSee('meta_json', false)
            ->assertDontSee('onerror=alert(2)', false);
    }

    private function createFinanceUser(): User
    {
        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $financeRole = Role::query()->where('key', 'finance')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Xss Finance User',
            'email' => 'xss-finance@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role_id' => $tenantOwnerRole->id,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role_id' => $financeRole->id,
        ]);

        return $user;
    }

    private function createAccount(string $displayName): CurrentAccount
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);

        return $account->fresh(['roles']);
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
