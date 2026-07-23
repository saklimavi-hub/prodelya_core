<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AdminMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantCurrencySettingsDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;
    private User $financeUser;
    private User $operatorUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::factory()->create([
            'default_currency' => 'TRY',
            'panel_subdomain' => 'currency-permission-main',
        ]);
        $this->enableCurrencySettingsAccess($this->tenant, true);

        $this->adminUser = $this->createTenantUser($this->tenant, 'currency-admin', ['manage_users']);
        $this->financeUser = $this->createTenantUser($this->tenant, 'currency-finance', ['view_order_finance_summary']);
        $this->operatorUser = $this->createTenantUser($this->tenant, 'currency-operator', []);
    }

    public function test_currency_settings_permission_truth_for_get_and_menu_visibility(): void
    {
        $this->assertTrue($this->adminUser->hasPermissionInTenant('manage_users', $this->tenant->id));
        $this->assertTrue($this->financeUser->canViewFinancialData($this->tenant->id));
        $this->assertFalse($this->operatorUser->hasPermissionInTenant('manage_users', $this->tenant->id));
        $this->assertFalse($this->operatorUser->canViewFinancialData($this->tenant->id));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get($this->tenantUrl('/admin/settings/currency', $this->tenant))
            ->assertOk();

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get($this->tenantUrl('/admin/settings/currency', $this->tenant))
            ->assertOk();

        $this->actingAs($this->operatorUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get($this->tenantUrl('/admin/settings/currency', $this->tenant))
            ->assertForbidden();

        $adminMenu = app(AdminMenuService::class)->tenantMenu($this->tenant, $this->adminUser);
        $financeMenu = app(AdminMenuService::class)->tenantMenu($this->tenant, $this->financeUser);
        $operatorMenu = app(AdminMenuService::class)->tenantMenu($this->tenant, $this->operatorUser);

        $this->assertMenuContainsRoute($adminMenu, 'admin.settings.currency');
        $this->assertMenuContainsRoute($financeMenu, 'admin.settings.currency');
        $this->assertMenuDoesNotContainRoute($operatorMenu, 'admin.settings.currency');
    }

    public function test_currency_settings_update_and_refresh_keep_unauthorized_and_foreign_tenant_denial(): void
    {
        $payload = [
            'base_currency' => 'TRY',
            'default_quote_currency' => 'TRY',
            'enabled_quote_currencies' => ['TRY', 'USD'],
            'currency_rate_source' => 'tcmb',
            'currency_rate_type' => 'forex_selling',
            'currency_stale_after_days' => 2,
            'currency_refresh_policy' => 'manual',
        ];

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->put($this->tenantUrl('/admin/settings/currency', $this->tenant), $payload)
            ->assertRedirect(route('admin.settings.currency'));

        $this->actingAs($this->operatorUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->put($this->tenantUrl('/admin/settings/currency', $this->tenant), $payload)
            ->assertForbidden();

        $this->actingAs($this->operatorUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->post($this->tenantUrl('/admin/settings/currency/refresh-rates', $this->tenant))
            ->assertForbidden();

        $foreignTenant = TenantAccount::factory()->create([
            'panel_subdomain' => 'currency-permission-foreign',
        ]);
        $this->enableCurrencySettingsAccess($foreignTenant, true);
        $foreignAdmin = $this->createTenantUser($foreignTenant, 'foreign-currency-admin', ['manage_users']);

        $this->actingAs($foreignAdmin)
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($this->tenant)])
            ->get($this->tenantUrl('/admin/settings/currency', $this->tenant))
            ->assertForbidden();
    }

    private function createTenantUser(TenantAccount $tenant, string $key, array $permissions): User
    {
        $user = User::factory()->create();

        $role = Role::factory()->create([
            'tenant_account_id' => $tenant->id,
            'key' => $key,
            'name' => ucfirst(str_replace('-', ' ', $key)),
            'permissions' => $permissions,
            'is_active' => true,
        ]);

        UserRole::factory()->create([
            'user_id' => $user->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }

    private function enableCurrencySettingsAccess(TenantAccount $tenant, bool $multiCurrencyEnabled): void
    {
        TenantModule::query()->updateOrCreate([
            'tenant_account_id' => $tenant->id,
            'module_key' => 'tenant_settings',
            'feature_key' => null,
        ], ['is_enabled' => true]);

        TenantModule::query()->updateOrCreate([
            'tenant_account_id' => $tenant->id,
            'module_key' => 'multi_currency',
            'feature_key' => null,
        ], ['is_enabled' => $multiCurrencyEnabled]);
    }

    private function tenantUrl(string $path, TenantAccount $tenant): string
    {
        return 'http://' . $this->tenantHost($tenant) . $path;
    }

    private function tenantHost(TenantAccount $tenant): string
    {
        return $tenant->panel_subdomain . '.' . self::CENTRAL_HOST;
    }

    private function assertMenuContainsRoute(array $menu, string $route): void
    {
        $this->assertTrue($this->menuContainsRoute($menu, $route), "Expected menu to contain {$route}.");
    }

    private function assertMenuDoesNotContainRoute(array $menu, string $route): void
    {
        $this->assertFalse($this->menuContainsRoute($menu, $route), "Expected menu not to contain {$route}.");
    }

    private function menuContainsRoute(array $items, string $route): bool
    {
        foreach ($items as $item) {
            if (($item['route'] ?? null) === $route) {
                return true;
            }

            if (!empty($item['children']) && $this->menuContainsRoute($item['children'], $route)) {
                return true;
            }
        }

        return false;
    }
}
