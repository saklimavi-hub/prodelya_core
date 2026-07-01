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

class FinanceMenuAuthorizationConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;
    private User $financeUser;
    private User $operationsUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenant->forceFill([
            'package_key' => 'enterprise',
            'panel_subdomain' => 'finance-menu-consistency',
            'slug' => 'finance-menu-consistency',
        ])->save();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->financeUser = $this->createUserWithRole('finance', 'finance-menu-consistency');
        $this->operationsUser = $this->createUserWithRole('delivery', 'operations-menu-consistency');

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'finance',
                'feature_key' => 'finance_summary',
            ],
            ['is_enabled' => true]
        );
    }

    public function test_finance_menu_requires_both_module_access_and_any_finance_permission(): void
    {
        $service = app(AdminMenuService::class);

        $adminLabels = $this->flattenLabels($service->tenantMenu($this->tenant->fresh(), $this->adminUser));
        $financeLabels = $this->flattenLabels($service->tenantMenu($this->tenant->fresh(), $this->financeUser));
        $operationsLabels = $this->flattenLabels($service->tenantMenu($this->tenant->fresh(), $this->operationsUser));

        $this->assertContains('Finans', $adminLabels);
        $this->assertContains('Finans', $financeLabels);
        $this->assertNotContains('Finans', $operationsLabels);
    }

    public function test_finance_route_remains_accessible_to_authorized_users_and_forbidden_for_unauthorized_users(): void
    {
        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.index'))
            ->assertOk();

        $this->actingAs($this->operationsUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.index'))
            ->assertForbidden();
    }

    public function test_finance_menu_hides_and_route_is_forbidden_when_finance_feature_is_disabled(): void
    {
        TenantModule::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('module_key', 'finance')
            ->where('feature_key', 'finance_summary')
            ->delete();

        TenantModule::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'module_key' => 'finance',
            'feature_key' => 'finance_summary',
            'is_enabled' => false,
        ]);

        $labels = $this->flattenLabels(app(AdminMenuService::class)->tenantMenu($this->tenant->fresh(), $this->financeUser));
        $this->assertNotContains('Finans', $labels);

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.finance.index'))
            ->assertForbidden();
    }

    public function test_dashboard_shows_finance_card_only_for_finance_authorized_users(): void
    {
        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Tahsilat ve Finans');

        $this->actingAs($this->operationsUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Tahsilat ve Finans');
    }

    private function createUserWithRole(string $roleKey, string $emailPrefix): User
    {
        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        $user = User::query()->create([
            'name' => ucfirst($roleKey) . ' Menu Test',
            'email' => $emailPrefix . '@example.test',
            'password' => 'password',
        ]);

        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'tenant_account_id' => $this->tenant->id,
        ]);

        return $user;
    }

    private function flattenLabels(array $items): array
    {
        $labels = [];

        foreach ($items as $item) {
            if (!empty($item['label'])) {
                $labels[] = $item['label'];
            }

            if (!empty($item['children']) && is_array($item['children'])) {
                $labels = array_merge($labels, $this->flattenLabels($item['children']));
            }
        }

        return $labels;
    }
}
