<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\TenantAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminTenantCreateStoreTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private Role $adminRole;
    private TenantAccount $demoTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->adminRole = Role::query()->where('key', 'admin')->firstOrFail();
        $this->demoTenant = TenantAccount::query()->where('panel_subdomain', 'demo')->firstOrFail();
    }

    public function test_platform_admin_can_open_create_form_and_index_button_points_to_real_route(): void
    {
        $index = $this->actingAs($this->platformAdmin, 'web')
            ->get($this->centralUrl('/admin/super-admin/tenants'));

        $index->assertOk();
        $index->assertSee(route('admin.super.tenants.create'), false);
        $index->assertDontSee('type="button" class="pd-btn pd-btn-primary">Yeni Abone Firma', false);

        $create = $this->actingAs($this->platformAdmin, 'web')
            ->get($this->centralUrl('/admin/super-admin/tenants/create'));

        $create->assertOk();
        $create->assertSee('Yeni Abone Firma Oluştur');
        $create->assertSee('Yönetici Kullanıcı');
        $create->assertSee('Abone Firma Oluştur ve Onboarding Hazırla');
    }

    public function test_tenant_admin_and_demo_admin_cannot_open_create_form(): void
    {
        $tenantAdmin = $this->createTenantAdmin(
            $this->createTenant('tenant-create-blocked'),
            'tenant-create-blocked@example.test'
        );
        $demoAdmin = $this->createTenantAdmin($this->demoTenant, 'demo-create-blocked@example.test');

        $this->actingAs($tenantAdmin, 'web')
            ->get($this->centralUrl('/admin/super-admin/tenants/create'))
            ->assertForbidden();

        $this->actingAs($demoAdmin, 'web')
            ->get($this->centralUrl('/admin/super-admin/tenants/create'))
            ->assertForbidden();
    }

    public function test_platform_admin_can_store_tenant_with_normalized_fields_and_package_assignment(): void
    {
        $package = Package::query()->where('key', 'suite')->where('status', 'active')->firstOrFail();

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->post($this->centralUrl('/admin/super-admin/tenants'), [
                'name' => 'Saklı Mavi',
                'legal_name' => 'Saklı Mavi Reklam',
                'slug' => 'Sakli Mavi',
                'panel_subdomain' => 'Sakli Mavi',
                'status' => 'active',
                'package_key' => $package->key,
                'default_locale' => 'tr',
                'default_currency' => 'tl',
                'timezone' => 'Europe/Istanbul',
                'custom_domain' => 'https://App.SakliMavi.test/path',
                'portal_domain' => 'HTTPS://Portal.SakliMavi.test/login',
            ]);

        $tenant = TenantAccount::query()->where('slug', 'sakli-mavi')->firstOrFail();

        $response->assertRedirect(route('admin.super.tenants.show', $tenant));
        $response->assertSessionHas('success');
        $response->assertSessionHas('onboarding_defaults_summary');

        $this->assertSame($package->key, $tenant->package_key);
        $this->assertSame('sakli-mavi', $tenant->slug);
        $this->assertSame('sakli-mavi', $tenant->panel_subdomain);
        $this->assertSame('TL', $tenant->default_currency);
        $this->assertSame('app.saklimavi.test', $tenant->custom_domain);
        $this->assertSame('portal.saklimavi.test', $tenant->portal_domain);
        $this->assertSame('tr_TR', $tenant->number_format_locale);
        $this->assertDatabaseCount('user_roles', 1);
        $this->assertDatabaseMissing('tenant_modules', [
            'tenant_account_id' => $tenant->id,
        ]);
        $this->assertTrue($tenant->settings()->exists());
        $this->assertTrue($tenant->notificationTemplates()->exists());
        $this->assertTrue($tenant->printSettings()->exists());

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->get(route('admin.super.tenants.show', $tenant));

        $show->assertOk();
        $show->assertSee('Owner kullanıcı henüz oluşturulmadı.');
        $show->assertSee('Tenant ayarları varsayılanları');

        $access = app(TenantAccessService::class);
        $this->assertTrue($access->canAccessModule($tenant, 'customer_portal'));
    }

    public function test_store_blocks_duplicate_and_reserved_identifiers_and_inactive_packages(): void
    {
        TenantAccount::query()->create([
            'name' => 'Existing Tenant',
            'legal_name' => 'Existing Tenant Ltd.',
            'slug' => 'existing-tenant',
            'panel_subdomain' => 'existing-tenant',
            'status' => 'active',
            'package_key' => 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $passivePackage = Package::query()->create([
            'key' => 'inactive-create-test',
            'name' => 'Inactive Create Test',
            'status' => 'passive',
            'currency' => 'TRY',
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->from($this->centralUrl('/admin/super-admin/tenants/create'))
            ->post($this->centralUrl('/admin/super-admin/tenants'), [
                'name' => 'Duplicate Tenant',
                'legal_name' => '',
                'slug' => 'existing-tenant',
                'panel_subdomain' => 'demo',
                'status' => 'active',
                'package_key' => $passivePackage->key,
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
            ])
            ->assertRedirect($this->centralUrl('/admin/super-admin/tenants/create'))
            ->assertSessionHasErrors(['slug', 'panel_subdomain', 'package_key']);
    }

    public function test_store_blocks_duplicate_panel_subdomain_and_duplicate_cross_domain_usage(): void
    {
        TenantAccount::query()->create([
            'name' => 'Existing Domain Tenant',
            'legal_name' => 'Existing Domain Tenant Ltd.',
            'slug' => 'existing-domain-tenant',
            'panel_subdomain' => 'existing-panel',
            'custom_domain' => 'taken.customer.test',
            'portal_domain' => 'portal.taken.customer.test',
            'status' => 'active',
            'package_key' => 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $package = Package::query()->where('key', 'starter')->where('status', 'active')->firstOrFail();

        $this->actingAs($this->platformAdmin, 'web')
            ->from($this->centralUrl('/admin/super-admin/tenants/create'))
            ->post($this->centralUrl('/admin/super-admin/tenants'), [
                'name' => 'Duplicate Domain Tenant',
                'legal_name' => '',
                'slug' => 'duplicate-domain-tenant',
                'panel_subdomain' => 'existing-panel',
                'status' => 'active',
                'package_key' => $package->key,
                'default_locale' => 'tr',
                'default_currency' => 'TL',
                'timezone' => 'Europe/Istanbul',
                'custom_domain' => 'portal.taken.customer.test',
                'portal_domain' => 'taken.customer.test',
            ])
            ->assertRedirect($this->centralUrl('/admin/super-admin/tenants/create'))
            ->assertSessionHasErrors(['panel_subdomain', 'custom_domain', 'portal_domain']);
    }

    private function createTenant(string $subdomain): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $subdomain)),
            'legal_name' => ucfirst(str_replace('-', ' ', $subdomain)) . ' Ltd.',
            'slug' => $subdomain,
            'panel_subdomain' => $subdomain,
            'status' => 'active',
            'package_key' => 'starter',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function createTenantAdmin(TenantAccount $tenant, string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $user->id,
            'tenant_account_id' => $tenant->id,
            'role_id' => $this->adminRole->id,
        ]);

        return $user;
    }

    private function centralUrl(string $path): string
    {
        return 'http://' . self::CENTRAL_HOST . $path;
    }
}
