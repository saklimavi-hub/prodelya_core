<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantPackageUpgradeRequest;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantPackageOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private Role $tenantOwnerRole;
    private Role $adminRole;
    private Role $salesRole;
    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->adminRole = Role::query()->where('key', 'admin')->firstOrFail();
        $this->salesRole = Role::query()->where('key', 'sales')->firstOrFail();
        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_tenant_admin_can_open_package_overview_and_see_usage_modules_and_requests(): void
    {
        $tenant = $this->createTenant('tenant-package-overview', 'promotion');
        $admin = $this->createTenantUser($tenant, $this->adminRole, 'tenant-package-admin@example.test');
        $foreignTenant = $this->createTenant('tenant-package-other', 'enterprise');

        $this->createTenantUser($tenant, $this->salesRole, 'tenant-package-sales-1@example.test');
        $this->createTenantUser($tenant, $this->salesRole, 'tenant-package-sales-2@example.test');

        for ($index = 1; $index <= 4; $index++) {
            Order::query()->create([
                'tenant_account_id' => $tenant->id,
                'order_family' => 'promotion',
                'order_mode' => 'product_sale_print',
                'document_type' => 'order',
                'document_number' => 'OVR-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'status' => 'pending',
                'workflow_status' => 'order_created',
                'invoice_status' => 'fis',
                'delivery_type' => 'Kargo',
                'currency' => 'TL',
                'created_by' => $this->platformAdmin->id,
            ]);
        }

        TenantSetting::setValue($tenant->id, 'limit_users', 2, 'integer');
        TenantSetting::setValue($tenant->id, 'limit_orders', 5, 'integer');
        TenantSetting::setValue($tenant->id, 'limit_products', 'unlimited', 'string');

        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $tenant->id, 'module_key' => 'customer_portal'],
            ['is_enabled' => true]
        );

        TenantPackageUpgradeRequest::query()->create([
            'tenant_account_id' => $tenant->id,
            'requested_by_user_id' => $admin->id,
            'current_package_key' => $tenant->package_key,
            'requested_package_key' => 'enterprise',
            'status' => TenantPackageUpgradeRequest::STATUS_NEW,
            'request_note' => 'Daha fazla kullanıcı ve modül gerekiyor.',
        ]);

        TenantPackageUpgradeRequest::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'requested_by_user_id' => $this->createTenantUser($foreignTenant, $this->adminRole, 'tenant-package-foreign@example.test')->id,
            'current_package_key' => $foreignTenant->package_key,
            'requested_package_key' => 'enterprise',
            'status' => TenantPackageUpgradeRequest::STATUS_APPROVED,
            'request_note' => 'Yabancı tenant talebi.',
        ]);

        $response = $this->actingAs($admin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get(route('admin.my-package.index'));

        $response->assertOk();
        $response->assertSee('Paketim ve Kullanımım');
        $response->assertSee($tenant->package?->name ?? 'promotion');
        $response->assertSee('Aşıldı');
        $response->assertSee('Yaklaşıyor');
        $response->assertSee('Limitsiz');
        $response->assertSee('Aktif Modüller');
        $response->assertSee('Musteri Portali');
        $response->assertSee('Yükseltilebilir Modüller');
        $response->assertSee('XML / Feed Hizmeti');
        $response->assertSee('Paket Taleplerim');
        $response->assertSee('Daha fazla kullanıcı ve modül gerekiyor.');
        $response->assertDontSee('Yabancı tenant talebi.');
        $response->assertSee(route('admin.package-requests.index'), false);
        $response->assertDontSee('Paket Ata');
        $response->assertDontSee('Modül Override');
        $response->assertDontSee('Limit Override');
    }

    public function test_tenant_owner_can_open_package_overview_and_see_package_details(): void
    {
        $tenant = $this->createTenant('tenant-package-owner', 'enterprise');
        $owner = $this->createTenantUser($tenant, $this->tenantOwnerRole, 'tenant-package-owner@example.test');

        $response = $this->actingAs($owner, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get(route('admin.my-package.index'));

        $response->assertOk();
        $response->assertSee('Mevcut Paketim');
        $response->assertSee($tenant->package?->name ?? 'enterprise');
        $response->assertSee('Kullanım Limitleri');
    }

    public function test_unauthorized_tenant_user_and_public_user_cannot_open_package_overview(): void
    {
        $tenant = $this->createTenant('tenant-package-guard', 'starter');
        $sales = $this->createTenantUser($tenant, $this->salesRole, 'tenant-package-noaccess@example.test');

        $this->actingAs($sales, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get(route('admin.my-package.index'))
            ->assertForbidden();

        $settings = $this->actingAs($sales, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get(route('admin.settings'));

        $settings->assertOk();
        $settings->assertDontSee(route('admin.my-package.index'), false);

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get(route('admin.my-package.index'))
            ->assertForbidden();
    }

    private function createTenant(string $subdomain, string $packageKey): TenantAccount
    {
        $package = Package::query()->where('key', $packageKey)->firstOrFail();

        return TenantAccount::query()->create([
            'name' => 'Tenant ' . $subdomain,
            'legal_name' => 'Tenant ' . $subdomain . ' Ltd.',
            'slug' => $subdomain,
            'panel_subdomain' => $subdomain,
            'status' => 'active',
            'package_key' => $package->key,
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function createTenantUser(TenantAccount $tenant, Role $role, string $email): User
    {
        $user = User::query()->create([
            'name' => ucfirst(str_replace(['@example.test', '-', '.'], ['', ' ', ' '], $email)),
            'email' => $email,
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }

    private function tenantHost(TenantAccount $tenant): string
    {
        return $tenant->panel_subdomain . '.' . self::CENTRAL_HOST;
    }
}
