<?php

namespace Tests\Feature\ProcessDepth;

use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\TenantAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessDepthAccessIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_depth_does_not_change_module_or_feature_access(): void
    {
        $package = Package::query()->create([
            'key' => 'isolation-package',
            'name' => 'Isolation Package',
            'status' => 'active',
            'currency' => 'TRY',
            'process_depth' => 'standard',
        ]);

        $tenant = TenantAccount::factory()->create([
            'package_key' => $package->key,
        ]);

        TenantModule::query()->create([
            'tenant_account_id' => $tenant->id,
            'module_key' => 'graphics',
            'is_enabled' => false,
        ]);

        TenantModule::query()->create([
            'tenant_account_id' => $tenant->id,
            'module_key' => 'quote_customer_approval',
            'feature_key' => 'public_quote_approval',
            'is_enabled' => false,
        ]);

        $service = app(TenantAccessService::class);

        $beforeGraphics = $service->canAccessModule($tenant->fresh(), 'graphics');
        $beforeFeature = $service->canAccessFeature($tenant->fresh(), 'public_quote_approval', 'quote_customer_approval');

        TenantSetting::setValue($tenant->id, 'process_depth', 'controlled', 'string');

        $this->assertSame($beforeGraphics, $service->canAccessModule($tenant->fresh(), 'graphics'));
        $this->assertSame($beforeFeature, $service->canAccessFeature($tenant->fresh(), 'public_quote_approval', 'quote_customer_approval'));
    }

    public function test_process_depth_does_not_change_user_permissions_or_finance_visibility(): void
    {
        $package = Package::query()->create([
            'key' => 'permission-package',
            'name' => 'Permission Package',
            'status' => 'active',
            'currency' => 'TRY',
            'process_depth' => 'standard',
        ]);

        $tenant = TenantAccount::factory()->create([
            'package_key' => $package->key,
        ]);

        $user = User::factory()->create();
        $role = Role::factory()->create([
            'tenant_account_id' => $tenant->id,
            'key' => 'finance-reader',
            'name' => 'Finance Reader',
            'permissions' => ['view_sales_prices'],
            'is_active' => true,
        ]);

        UserRole::factory()->create([
            'user_id' => $user->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $role->id,
        ]);

        $beforePermission = $user->hasPermissionInTenant('view_sales_prices', $tenant->id);
        $beforeFinance = $user->canViewFinancialData($tenant->id);

        TenantSetting::setValue($tenant->id, 'process_depth', 'fast', 'string');

        $user = $user->fresh();

        $this->assertSame($beforePermission, $user->hasPermissionInTenant('view_sales_prices', $tenant->id));
        $this->assertSame($beforeFinance, $user->canViewFinancialData($tenant->id));
    }
}
