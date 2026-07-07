<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickActionXssSafetyTest extends TestCase
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

    public function test_quick_panel_fields_are_escaped_and_meta_is_not_rendered(): void
    {
        [$account, $company] = $this->createLinkedCompanyAccount('Güvenli Hızlı Panel Cari');

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'), [
                'transaction_type' => 'customer_debit',
                'amount' => 150,
                'currency' => 'TL',
                'transaction_date' => '2026-07-04',
                'status' => 'open',
                'document_number' => '<b>DOC-<script>1</script></b>',
                'description' => '<script>alert(1)</script> Güvenli açıklama',
                'internal_note' => '<img src=x onerror=alert(2)> İç not',
                'redirect_to' => $this->tenantUrl('/admin/companies/' . $company->id . '?tab=ekstre'),
            ])
            ->assertRedirect($this->tenantUrl('/admin/companies/' . $company->id . '?tab=ekstre'));

        $companyResponse = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '?tab=ekstre'));

        $statementResponse = $this->actingAs($this->financeUser, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $account->id . '/transactions'));

        foreach ([$companyResponse, $statementResponse] as $response) {
            $response->assertOk()
                ->assertSee('alert(1) Güvenli açıklama')
                ->assertSee('DOC-1')
                ->assertDontSee('<script>alert(1)</script>', false)
                ->assertDontSee('<img src=x onerror=alert(2)>', false)
                ->assertDontSee('meta_json', false)
                ->assertDontSee('onerror=alert(2)', false);
        }
    }

    private function createLinkedCompanyAccount(string $displayName): array
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);
        $company = app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, [CurrentAccountRole::ROLE_CUSTOMER]);

        return [$account->fresh(['roles', 'links']), Company::query()->findOrFail($company->id)];
    }

    private function createFinanceUser(): User
    {
        $user = User::query()->create([
            'name' => 'Quick Xss Finance',
            'email' => 'quick-xss-finance@example.test',
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
