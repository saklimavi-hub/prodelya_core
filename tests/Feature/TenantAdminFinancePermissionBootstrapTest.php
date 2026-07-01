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

class TenantAdminFinancePermissionBootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private TenantAccount $otherTenant;
    private User $tenantOwnerUser;
    private User $adminUser;
    private User $operationsUser;
    private User $brokenTenantAdminUser;
    private User $otherTenantAdminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenant->forceFill([
            'name' => 'Finance Bootstrap Tenant',
            'legal_name' => 'Finance Bootstrap Tenant Ltd. Şti.',
            'slug' => 'finance-bootstrap-tenant',
            'panel_subdomain' => 'finance-bootstrap-tenant',
            'package_key' => 'enterprise',
        ])->save();

        $this->otherTenant = TenantAccount::query()->create([
            'name' => 'Other Finance Tenant',
            'legal_name' => 'Other Finance Tenant Ltd. Şti.',
            'slug' => 'other-finance-tenant',
            'panel_subdomain' => 'other-finance-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        foreach ([$this->tenant, $this->otherTenant] as $tenant) {
            TenantModule::query()->updateOrCreate(
                [
                    'tenant_account_id' => $tenant->id,
                    'module_key' => 'finance',
                    'feature_key' => 'finance_summary',
                ],
                ['is_enabled' => true]
            );
        }

        $this->tenantOwnerUser = $this->createUserWithRole('tenant_owner', $this->tenant, 'owner-finance-bootstrap@example.test');
        $this->adminUser = $this->createUserWithRole('admin', $this->tenant, 'admin-finance-bootstrap@example.test');
        $this->operationsUser = $this->createUserWithRole('delivery', $this->tenant, 'ops-finance-bootstrap@example.test');

        $brokenRole = Role::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'name' => 'Tenant Admin',
            'key' => 'tenant_admin',
            'description' => 'Broken tenant admin for repair test',
            'permissions' => [
                'orders' => ['view_orders'],
            ],
            'is_system' => false,
            'is_active' => true,
        ]);

        $this->brokenTenantAdminUser = User::query()->create([
            'name' => 'Broken Tenant Admin',
            'email' => 'broken-tenant-admin@example.test',
            'password' => 'password',
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->brokenTenantAdminUser->id,
            'role_id' => $brokenRole->id,
        ]);

        $this->otherTenantAdminUser = User::query()->create([
            'name' => 'Other Tenant Admin',
            'email' => 'other-tenant-admin@example.test',
            'password' => 'password',
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->otherTenant->id,
            'user_id' => $this->otherTenantAdminUser->id,
            'role_id' => $brokenRole->id,
        ]);
    }

    public function test_tenant_owner_and_admin_roles_bootstrap_finance_permissions_while_operations_role_does_not(): void
    {
        $required = [
            'view_order_finance_summary',
            'view_payment_details',
            'manage_payments',
            'mark_payments_received',
        ];

        foreach ($required as $permission) {
            $this->assertTrue($this->tenantOwnerUser->hasPermissionInTenant($permission, $this->tenant->id));
            $this->assertTrue($this->adminUser->hasPermissionInTenant($permission, $this->tenant->id));
            $this->assertFalse($this->operationsUser->hasPermissionInTenant($permission, $this->tenant->id));
        }
    }

    public function test_finance_menu_and_route_follow_bootstrapped_permissions(): void
    {
        $labels = $this->flattenLabels(app(AdminMenuService::class)->tenantMenu($this->tenant->fresh(), $this->tenantOwnerUser));
        $this->assertContains('Finans', $labels);

        $this->actingAs($this->tenantOwnerUser)
            ->get('http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . '/admin/finance')
            ->assertOk();

        $opsLabels = $this->flattenLabels(app(AdminMenuService::class)->tenantMenu($this->tenant->fresh(), $this->operationsUser));
        $this->assertNotContains('Finans', $opsLabels);

        $this->actingAs($this->operationsUser)
            ->get('http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . '/admin/finance')
            ->assertForbidden();
    }

    public function test_repair_command_dry_run_is_non_destructive_and_real_run_repairs_only_target_tenant(): void
    {
        $this->assertFalse($this->brokenTenantAdminUser->hasPermissionInTenant('view_order_finance_summary', $this->tenant->id));
        $this->assertDatabaseMissing('user_roles', [
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->brokenTenantAdminUser->id,
            'role_id' => Role::query()->where('key', 'finance')->value('id'),
        ]);

        $this->artisan('prodelya:ensure-tenant-admin-permissions', [
            '--tenant' => $this->tenant->slug,
            '--email' => $this->brokenTenantAdminUser->email,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Planlanan finance rol ataması: 1')
            ->expectsOutputToContain('Dry-run: Veri yazılmadı.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('user_roles', [
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->brokenTenantAdminUser->id,
            'role_id' => Role::query()->where('key', 'finance')->value('id'),
        ]);

        $this->artisan('prodelya:ensure-tenant-admin-permissions', [
            '--tenant' => $this->tenant->slug,
            '--email' => $this->brokenTenantAdminUser->email,
        ])
            ->expectsOutputToContain('Eklenen finance rol ataması: 1')
            ->assertExitCode(0);

        $this->assertTrue($this->brokenTenantAdminUser->fresh()->hasPermissionInTenant('view_order_finance_summary', $this->tenant->id));
        $this->assertFalse($this->otherTenantAdminUser->fresh()->hasPermissionInTenant('view_order_finance_summary', $this->otherTenant->id));
        $this->assertDatabaseMissing('user_roles', [
            'tenant_account_id' => $this->otherTenant->id,
            'user_id' => $this->otherTenantAdminUser->id,
            'role_id' => Role::query()->where('key', 'finance')->value('id'),
        ]);
    }

    private function createUserWithRole(string $roleKey, TenantAccount $tenant, string $email): User
    {
        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }

    private function flattenLabels(array $items): array
    {
        $labels = [];

        foreach ($items as $item) {
            if (! empty($item['label'])) {
                $labels[] = $item['label'];
            }

            if (! empty($item['children']) && is_array($item['children'])) {
                $labels = array_merge($labels, $this->flattenLabels($item['children']));
            }
        }

        return $labels;
    }
}
