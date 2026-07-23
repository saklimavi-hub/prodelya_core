<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantResolverDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenant->forceFill([
            'panel_subdomain' => 'tenant-resolver-diagnostic',
            'slug' => 'tenant-resolver-diagnostic',
            'default_currency' => 'TRY',
        ])->save();

        $this->adminUser = User::factory()->create();
        $adminRole = Role::query()->where('key', 'admin')->firstOrFail();

        UserRole::factory()->create([
            'user_id' => $this->adminUser->id,
            'tenant_account_id' => $this->tenant->id,
            'role_id' => $adminRole->id,
        ]);

        foreach (['tenant_settings', 'multi_currency'] as $moduleKey) {
            TenantModule::query()->updateOrCreate(
                [
                    'tenant_account_id' => $this->tenant->id,
                    'module_key' => $moduleKey,
                    'feature_key' => null,
                ],
                ['is_enabled' => true]
            );
        }
    }

    public function test_tenant_resolver_debug(): void
    {
        $request = request()->duplicate(server: [
            'HTTP_HOST' => $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST,
        ]);

        $tenantResolver = $this->app->make(\App\Services\TenantResolver::class);
        $resolvedTenant = $tenantResolver->resolve($request);

        $this->assertSame($this->tenant->id, $resolvedTenant?->id);
        $this->assertTrue($this->adminUser->hasPermissionInTenant('manage_users', $this->tenant->id));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST])
            ->get(route('admin.settings.currency'))
            ->assertOk();
    }
}
