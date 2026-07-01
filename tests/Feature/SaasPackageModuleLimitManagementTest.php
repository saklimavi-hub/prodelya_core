<?php

namespace Tests\Feature;

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

class SaasPackageModuleLimitManagementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private Role $tenantOwnerRole;
    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->adminRole = Role::query()->where('key', 'admin')->firstOrFail();
    }

    public function test_super_admin_package_module_and_limit_surfaces_show_operational_data(): void
    {
        $suitePackage = Package::query()->where('key', 'suite')->firstOrFail();

        $tenant = TenantAccount::query()->create([
            'name' => 'Paket Operasyon Tenant',
            'legal_name' => 'Paket Operasyon Tenant Ltd.',
            'slug' => 'paket-operasyon-tenant',
            'panel_subdomain' => 'paket-operasyon-tenant',
            'status' => 'active',
            'package_key' => $suitePackage->key,
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $owner = User::query()->create([
            'name' => 'Paket Owner',
            'email' => 'paket-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        $admin = User::query()->create([
            'name' => 'Paket Admin',
            'email' => 'paket-admin@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $owner->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        UserRole::query()->create([
            'user_id' => $admin->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $this->adminRole->id,
        ]);

        TenantModule::query()->create([
            'tenant_account_id' => $tenant->id,
            'module_key' => 'customer_portal',
            'is_enabled' => false,
        ]);

        TenantModule::query()->create([
            'tenant_account_id' => $tenant->id,
            'module_key' => 'quote_customer_approval',
            'feature_key' => 'public_quote_approval',
            'is_enabled' => false,
        ]);

        TenantSetting::setValue($tenant->id, 'limit_users', 1, 'integer');

        $packageIndex = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.packages.index'));

        $packageIndex->assertOk();
        $packageIndex->assertSee('Kullanıcı');
        $packageIndex->assertSee('Ürün / Katalog');
        $packageIndex->assertSee('Tedarikçi');
        $packageIndex->assertSee('Aktif Abone Firma');

        $packageShow = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.packages.show', $suitePackage));

        $packageShow->assertOk();
        $packageShow->assertSee('Dahil Modüller');
        $packageShow->assertSee('Dahil Özellikler');
        $packageShow->assertSee('Bu Paketi Kullanan Abone Firmalar');
        $packageShow->assertSee($tenant->name);
        $packageShow->assertSee('Tenant override var');

        $moduleIndex = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.modules'));

        $moduleIndex->assertOk();
        $moduleIndex->assertSee('Core / Zorunlu Modüller');
        $moduleIndex->assertSee('Product Hub / Katalog Modülleri');
        $moduleIndex->assertSee('Müşteri Yüzeyleri');
        $moduleIndex->assertSee('Paket veya tenant override ile görünür');
        $moduleIndex->assertSee('Menü ve route erişimine kapalı');

        $moduleSettings = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.settings'));

        $moduleSettings->assertOk();
        $moduleSettings->assertSee('Karar Özeti');
        $moduleSettings->assertSee('Paket Matrisi');

        $tenantShow = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.show', $tenant));

        $tenantShow->assertOk();
        $tenantShow->assertSee('Modül / Override Özeti');
        $tenantShow->assertSee('Özellik Özeti');
        $tenantShow->assertSee('Limit Özeti');
        $tenantShow->assertSee('Tenant override');
        $tenantShow->assertSee('Paket varsayılanı');
        $tenantShow->assertSee('Uyarı');

        $access = app(TenantAccessService::class);
        $this->assertTrue($access->canAccessModule($tenant->fresh(), 'core'));
        $this->assertFalse($access->canAccessModule($tenant->fresh(), 'xml_export'));
        $this->assertFalse($access->canAccessModule($tenant->fresh(), 'customer_portal'));
        $this->assertFalse($access->canAccessFeature($tenant->fresh(), 'public_quote_approval', 'quote_customer_approval'));
    }
}
