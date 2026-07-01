<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantServiceDefinition;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantServiceCatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private TenantAccount $tenant;
    private Role $tenantAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenantAdminRole = Role::query()->where('key', 'admin')->firstOrFail();
    }

    public function test_super_admin_can_view_service_catalog_and_seeded_rows(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.services.index'));

        $response->assertOk();
        $response->assertSee('Tenant Hizmetleri');
        $response->assertSee('Kurulum ve Onboarding');
        $response->assertSee('ONBOARDING');
    }

    public function test_super_admin_can_create_and_update_service_definition(): void
    {
        $create = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.services.store'), [
                'service_code' => 'QA_SUPPORT',
                'service_name' => 'QA Destek Hizmeti',
                'category' => 'Destek',
                'default_direction' => 'debit',
                'default_amount' => 1250.50,
                'currency' => 'TRY',
                'description' => 'Tenant için QA destek hizmeti.',
                'is_active' => '1',
                'sort_order' => 90,
            ]);

        $service = TenantServiceDefinition::query()->where('service_code', 'QA_SUPPORT')->firstOrFail();

        $create->assertRedirect(route('admin.super.services.edit', $service));
        $this->assertSame('QA Destek Hizmeti', $service->service_name);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.super.services.update', $service), [
                'service_code' => 'QA_SUPPORT',
                'service_name' => 'QA Destek Paketi',
                'category' => 'Destek',
                'default_direction' => 'debit',
                'default_amount' => 2500,
                'currency' => 'TRY',
                'description' => 'Güncellenmiş servis açıklaması.',
                'sort_order' => 91,
            ])
            ->assertRedirect(route('admin.super.services.edit', $service))
            ->assertSessionHas('success');

        $service->refresh();
        $this->assertSame('QA Destek Paketi', $service->service_name);
        $this->assertFalse($service->is_active);
    }

    public function test_tenant_admin_cannot_access_super_admin_service_catalog(): void
    {
        $tenantAdmin = User::query()->create([
            'name' => 'Tenant Admin',
            'email' => 'tenant-service-admin@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $tenantAdmin->id,
            'tenant_account_id' => $this->tenant->id,
            'role_id' => $this->tenantAdminRole->id,
        ]);

        $this->actingAs($tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.services.index'))
            ->assertForbidden();
    }
}
