<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantPackageUpgradeRequest;
use App\Models\TenantSetting;
use App\Models\TenantSupplierAccess;
use App\Models\TenantUpgradeRequest;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantUpgradeRequestCenterTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private Role $adminRole;
    private Role $tenantOwnerRole;
    private Role $salesRole;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->adminRole = Role::query()->where('key', 'admin')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->salesRole = Role::query()->where('key', 'sales')->firstOrFail();
    }

    public function test_tenant_admin_can_open_request_center_and_only_see_own_requests(): void
    {
        $tenant = $this->createTenant('upgrade-center-a', 'starter');
        $admin = $this->createTenantUser($tenant, $this->adminRole, 'upgrade-center-admin@example.test');
        $otherTenant = $this->createTenant('upgrade-center-b', 'promotion');
        $otherAdmin = $this->createTenantUser($otherTenant, $this->adminRole, 'upgrade-center-other@example.test');

        TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $tenant->id,
            'requested_by_user_id' => $admin->id,
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'status' => TenantUpgradeRequest::STATUS_PENDING,
            'requested_service_key' => 'custom_integration',
            'requested_note' => 'Bize özel entegrasyon gerekli.',
        ]);

        TenantUpgradeRequest::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'requested_by_user_id' => $otherAdmin->id,
            'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
            'status' => TenantUpgradeRequest::STATUS_PENDING,
            'requested_service_key' => 'custom_integration',
            'requested_note' => 'Bu kayıt görünmemeli.',
        ]);

        $response = $this->actingAs($admin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get(route('admin.upgrade-requests.index'));

        $response->assertOk();
        $response->assertSee('Talep Merkezi');
        $response->assertSee('Yeni Talep Oluştur');
        $response->assertSee('Taleplerim');
        $response->assertSee('Paket Yükseltme');
        $response->assertSee('Ek Modül Talebi');
        $response->assertSee('Bize özel entegrasyon gerekli.');
        $response->assertDontSee('Bu kayıt görünmemeli.');
        $response->assertSee(route('admin.package-requests.index'), false);
    }

    public function test_tenant_owner_can_open_but_unauthorized_user_and_public_cannot(): void
    {
        $tenant = $this->createTenant('upgrade-center-guard', 'starter');
        $owner = $this->createTenantUser($tenant, $this->tenantOwnerRole, 'upgrade-center-owner@example.test');
        $sales = $this->createTenantUser($tenant, $this->salesRole, 'upgrade-center-sales@example.test');

        $this->actingAs($owner, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get(route('admin.upgrade-requests.index'))
            ->assertOk()
            ->assertSee('Talep Merkezi');

        $this->actingAs($sales, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get(route('admin.upgrade-requests.index'))
            ->assertForbidden();

        $this->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get(route('admin.upgrade-requests.index'))
            ->assertForbidden();
    }

    public function test_package_module_feature_limit_supplier_and_service_requests_can_be_created_through_center(): void
    {
        $tenant = $this->createTenant('upgrade-center-create', 'starter');
        $admin = $this->createTenantUser($tenant, $this->adminRole, 'upgrade-center-create@example.test');
        $supplier = Supplier::query()->create([
            'name' => 'Talep Supplier',
            'code' => 'TRQ-SUP-001',
            'status' => 'active',
        ]);

        TenantSetting::setValue($tenant->id, 'limit_orders', 10, 'integer');

        $this->actingAs($admin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->post(route('admin.upgrade-requests.store'), [
                'request_type' => TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE,
                'requested_package_key' => 'promotion',
                'requested_note' => 'Paket yükseltelim',
            ])
            ->assertRedirect(route('admin.upgrade-requests.index', ['type' => 'package_upgrade']));

        $this->actingAs($admin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->post(route('admin.upgrade-requests.store'), [
                'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
                'requested_module_key' => 'customer_portal',
            ])
            ->assertRedirect(route('admin.upgrade-requests.index', ['type' => 'module_addon']));

        $this->actingAs($admin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->post(route('admin.upgrade-requests.store'), [
                'request_type' => TenantUpgradeRequest::TYPE_FEATURE_ADDON,
                'requested_feature_key' => 'public_quote_approval',
            ])
            ->assertRedirect(route('admin.upgrade-requests.index', ['type' => 'feature_addon']));

        $this->actingAs($admin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->post(route('admin.upgrade-requests.store'), [
                'request_type' => TenantUpgradeRequest::TYPE_LIMIT_INCREASE,
                'requested_limit_key' => 'orders',
                'requested_limit_value' => 20,
            ])
            ->assertRedirect(route('admin.upgrade-requests.index', ['type' => 'limit_increase']));

        $this->actingAs($admin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->post(route('admin.upgrade-requests.store'), [
                'request_type' => TenantUpgradeRequest::TYPE_SUPPLIER_ACCESS,
                'requested_supplier_id' => $supplier->id,
            ])
            ->assertRedirect(route('admin.upgrade-requests.index', ['type' => 'supplier_access']));

        $this->actingAs($admin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->post(route('admin.upgrade-requests.store'), [
                'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
                'requested_service_key' => 'custom_integration',
                'requested_note' => 'Destek talebi',
            ])
            ->assertRedirect(route('admin.upgrade-requests.index', ['type' => 'service_request']));

        $this->assertDatabaseCount('tenant_upgrade_requests', 6);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant_upgrade_request_created',
            'entity_type' => 'tenant_upgrade_request',
        ]);
    }

    public function test_duplicate_and_invalid_requests_are_blocked_in_ui_flow(): void
    {
        $tenant = $this->createTenant('upgrade-center-blocks', 'promotion');
        $admin = $this->createTenantUser($tenant, $this->adminRole, 'upgrade-center-blocks@example.test');
        $supplier = Supplier::query()->create([
            'name' => 'Already Active Supplier',
            'code' => 'TRQ-SUP-002',
            'status' => 'active',
        ]);

        TenantSetting::setValue($tenant->id, 'limit_orders', 'unlimited', 'string');
        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $tenant->id, 'module_key' => 'customer_portal'],
            ['is_enabled' => true]
        );
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
            'export_allowed' => false,
        ]);

        $this->actingAs($admin, 'web')
            ->from(route('admin.upgrade-requests.index', ['type' => 'package_upgrade']))
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->post(route('admin.upgrade-requests.store'), [
                'request_type' => TenantUpgradeRequest::TYPE_PACKAGE_UPGRADE,
                'requested_package_key' => 'promotion',
            ])
            ->assertRedirect(route('admin.upgrade-requests.index', ['type' => 'package_upgrade']))
            ->assertSessionHasErrors(['requested_package_key']);

        $this->actingAs($admin, 'web')
            ->from(route('admin.upgrade-requests.index', ['type' => 'module_addon']))
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->post(route('admin.upgrade-requests.store'), [
                'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
                'requested_module_key' => 'core',
            ])
            ->assertRedirect(route('admin.upgrade-requests.index', ['type' => 'module_addon']))
            ->assertSessionHasErrors(['requested_module_key']);

        $this->actingAs($admin, 'web')
            ->from(route('admin.upgrade-requests.index', ['type' => 'module_addon']))
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->post(route('admin.upgrade-requests.store'), [
                'request_type' => TenantUpgradeRequest::TYPE_MODULE_ADDON,
                'requested_module_key' => 'customer_portal',
            ])
            ->assertRedirect(route('admin.upgrade-requests.index', ['type' => 'module_addon']))
            ->assertSessionHasErrors(['requested_module_key']);

        $this->actingAs($admin, 'web')
            ->from(route('admin.upgrade-requests.index', ['type' => 'limit_increase']))
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->post(route('admin.upgrade-requests.store'), [
                'request_type' => TenantUpgradeRequest::TYPE_LIMIT_INCREASE,
                'requested_limit_key' => 'orders',
                'requested_limit_value' => 99,
            ])
            ->assertRedirect(route('admin.upgrade-requests.index', ['type' => 'limit_increase']))
            ->assertSessionHasErrors(['requested_limit_key']);

        $this->actingAs($admin, 'web')
            ->from(route('admin.upgrade-requests.index', ['type' => 'supplier_access']))
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->post(route('admin.upgrade-requests.store'), [
                'request_type' => TenantUpgradeRequest::TYPE_SUPPLIER_ACCESS,
                'requested_supplier_id' => $supplier->id,
            ])
            ->assertRedirect(route('admin.upgrade-requests.index', ['type' => 'supplier_access']))
            ->assertSessionHasErrors(['requested_supplier_id']);
    }

    public function test_requested_note_is_escaped_and_my_package_links_to_request_center(): void
    {
        $tenant = $this->createTenant('upgrade-center-xss', 'starter');
        $admin = $this->createTenantUser($tenant, $this->adminRole, 'upgrade-center-xss@example.test');

        $this->actingAs($admin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->post(route('admin.upgrade-requests.store'), [
                'request_type' => TenantUpgradeRequest::TYPE_SERVICE_REQUEST,
                'requested_service_key' => 'custom_integration',
                'requested_note' => '<script>alert("xss")</script>Güvenli not',
            ])
            ->assertRedirect(route('admin.upgrade-requests.index', ['type' => 'service_request']));

        $center = $this->actingAs($admin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get(route('admin.upgrade-requests.index'));

        $center->assertOk();
        $center->assertSeeText('Güvenli not');
        $center->assertDontSee('<script>', false);

        $packageOverview = $this->actingAs($admin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->get(route('admin.my-package.index'));

        $packageOverview->assertOk();
        $packageOverview->assertSee(route('admin.upgrade-requests.index'), false);
    }

    public function test_existing_old_package_request_flow_still_works_alongside_generic_center(): void
    {
        $tenant = $this->createTenant('upgrade-center-legacy', 'starter');
        $admin = $this->createTenantUser($tenant, $this->adminRole, 'upgrade-center-legacy@example.test');

        $this->actingAs($admin, 'web')
            ->withServerVariables(['HTTP_HOST' => $this->tenantHost($tenant)])
            ->post(route('admin.package-requests.store'), [
                'requested_package_key' => 'promotion',
                'request_note' => 'Eski akış çalışmalı.',
            ])
            ->assertRedirect(route('admin.package-requests.index'));

        $this->assertDatabaseHas('tenant_package_upgrade_requests', [
            'tenant_account_id' => $tenant->id,
            'requested_package_key' => 'promotion',
        ]);
    }

    private function createTenant(string $subdomain, string $packageKey): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => 'Tenant ' . $subdomain,
            'legal_name' => 'Tenant ' . $subdomain . ' Ltd.',
            'slug' => $subdomain,
            'panel_subdomain' => $subdomain,
            'status' => 'active',
            'package_key' => Package::query()->where('key', $packageKey)->value('key') ?? $packageKey,
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function createTenantUser(TenantAccount $tenant, Role $role, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Upgrade Center ' . $email,
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
