<?php

namespace Tests\Feature;

use App\Models\CurrentAccount;
use App\Models\CurrentAccountRole;
use App\Models\Order;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickActionTenantPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private TenantAccount $foreignTenant;
    private User $financeUser;
    private User $productionUser;
    private User $foreignFinanceUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->foreignTenant = TenantAccount::query()->create([
            'name' => 'Quick Action Foreign',
            'legal_name' => 'Quick Action Foreign Ltd.',
            'slug' => 'quick-action-foreign',
            'panel_subdomain' => 'quick-action-foreign',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
        $this->financeUser = $this->createUserWithRoles('quick-tenant-finance@example.test', ['finance']);
        $this->productionUser = $this->createUserWithRoles('quick-tenant-production@example.test', ['production']);
        $this->foreignFinanceUser = $this->createUserWithRoles('quick-tenant-foreign-finance@example.test', ['finance'], $this->foreignTenant);
    }

    public function test_quick_panel_respects_tenant_isolation_and_permissions(): void
    {
        $localAccount = $this->createAccount($this->tenant, 'Yerel Cari');
        $foreignAccount = $this->createAccount($this->foreignTenant, 'Yabancı Cari');
        $foreignOrder = Order::query()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'document_number' => 'FRN-001',
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->foreignFinanceUser->id,
        ]);

        $this->actingAs($this->productionUser, 'web')
            ->post($this->tenantUrl($this->tenant, '/admin/current-accounts/' . $localAccount->id . '/transactions'), [
                'transaction_type' => 'customer_payment',
                'amount' => 50,
                'currency' => 'TL',
                'transaction_date' => '2026-07-04',
                'status' => 'closed',
            ])
            ->assertForbidden();

        $this->actingAs($this->financeUser, 'web')
            ->post($this->tenantUrl($this->tenant, '/admin/current-accounts/' . $foreignAccount->id . '/transactions'), [
                'transaction_type' => 'customer_payment',
                'amount' => 50,
                'currency' => 'TL',
                'transaction_date' => '2026-07-04',
                'status' => 'closed',
            ])
            ->assertForbidden();

        $statementUrl = $this->tenantUrl($this->tenant, '/admin/current-accounts/' . $localAccount->id . '/transactions');

        $this->actingAs($this->financeUser, 'web')
            ->from($statementUrl)
            ->post($statementUrl, [
                'transaction_type' => 'customer_payment',
                'amount' => 50,
                'currency' => 'TL',
                'transaction_date' => '2026-07-04',
                'status' => 'closed',
                'order_id' => $foreignOrder->id,
            ])
            ->assertRedirect($statementUrl)
            ->assertSessionHasErrors(['order_id']);

        $this->assertSame(0, $localAccount->fresh()->transactions()->count());
    }

    private function createAccount(TenantAccount $tenant, string $displayName): CurrentAccount
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'default_currency' => 'TL',
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, [CurrentAccountRole::ROLE_CUSTOMER]);

        return $account->fresh(['roles']);
    }

    private function createUserWithRoles(string $email, array $roleKeys, ?TenantAccount $tenant = null): User
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        $tenant ??= $this->tenant;

        foreach ($roleKeys as $roleKey) {
            UserRole::query()->create([
                'tenant_account_id' => $tenant->id,
                'user_id' => $user->id,
                'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id,
            ]);
        }

        return $user;
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
