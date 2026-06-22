<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccountLink;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyCreateRouteConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $owner;
    private User $platformAdmin;
    private Role $tenantOwnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->owner = User::query()->create([
            'name' => 'Route Consistency Owner',
            'email' => 'route-consistency-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        $this->enableCurrentAccounts();
        $this->enableCustomerPortal();
    }

    public function test_company_create_is_the_primary_user_facing_create_route(): void
    {
        $response = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/create'));

        $response->assertOk()
            ->assertSee('VKN / TCKN')
            ->assertSee('Açık Fatura Adresi')
            ->assertSee('Kurumsal Firma')
            ->assertSee('Bireysel Kişi')
            ->assertSee('+ Yetkili Ekle')
            ->assertDontSee('Vergi No')
            ->assertDontSee('TC No')
            ->assertDontSee('Sorgula');
    }

    public function test_current_account_create_redirects_to_company_create_form(): void
    {
        $response = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/create'));

        $response->assertRedirect($this->tenantUrl('/admin/companies/create'));

        $this->followingRedirects()
            ->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/create'))
            ->assertOk()
            ->assertSee('VKN / TCKN')
            ->assertSee('Açık Fatura Adresi')
            ->assertDontSee('Vergi No')
            ->assertDontSee('TC No');
    }

    public function test_user_facing_new_cari_buttons_point_to_company_create(): void
    {
        $currentAccountIndex = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/current-accounts'));

        $currentAccountIndex->assertOk()
            ->assertSee($this->tenantUrl('/admin/companies/create'), false)
            ->assertDontSee($this->tenantUrl('/admin/current-accounts/create'), false);

        $companyIndex = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies'));

        $companyIndex->assertOk()
            ->assertSee($this->tenantUrl('/admin/companies/create'), false);
    }

    public function test_company_create_store_still_syncs_current_account_and_uses_turkish_validation(): void
    {
        $invalid = $this->actingAs($this->owner, 'web')
            ->from($this->tenantUrl('/admin/companies/create'))
            ->post($this->tenantUrl('/admin/companies'), [
                'identity_type' => 'company',
                'legal_name' => 'Route Validation Company',
                'tax_number' => '12345ABCDE',
                'status' => 'active',
                'roles' => ['customer'],
            ]);

        $invalid->assertRedirect($this->tenantUrl('/admin/companies/create'))
            ->assertSessionHasErrors('tax_number');
        $this->assertSame(
            'VKN / TCKN 10 veya 11 haneli rakamlardan oluşmalıdır.',
            $invalid->getSession()->get('errors')->first('tax_number')
        );

        $store = $this->actingAs($this->owner, 'web')
            ->post($this->tenantUrl('/admin/companies'), [
                'identity_type' => 'company',
                'legal_name' => 'Route Sync Company',
                'tax_number' => '1234567890',
                'status' => 'active',
                'roles' => ['customer'],
            ]);

        $company = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'Route Sync Company')
            ->firstOrFail();

        $store->assertRedirect($this->tenantUrl('/admin/companies/' . $company->id));

        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'link_type' => CurrentAccountLink::LINK_COMPANY,
            'link_id' => $company->id,
        ]);
    }

    public function test_tenant_scope_is_preserved_for_create_route(): void
    {
        $foreignTenant = TenantAccount::query()->create([
            'name' => 'Foreign Tenant',
            'legal_name' => 'Foreign Tenant Ltd.',
            'slug' => 'foreign-route-consistency',
            'panel_subdomain' => 'foreign-route-consistency',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $foreignOwner = User::query()->create([
            'name' => 'Foreign Route Owner',
            'email' => 'foreign-route-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'user_id' => $foreignOwner->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/create'))
            ->assertOk();

        $this->actingAs($this->platformAdmin, 'web')
            ->get($this->tenantUrl('/admin/companies/create'))
            ->assertForbidden();

        $this->actingAs($foreignOwner, 'web')
            ->get($this->tenantUrl('/admin/companies/create'))
            ->assertForbidden();
    }

    private function enableCurrentAccounts(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'current_accounts',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );
    }

    private function enableCustomerPortal(): void
    {
        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'customer_portal',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantSetting::setValue($this->tenant->id, 'portal_enabled', true, 'boolean');
        TenantSetting::setValue($this->tenant->id, 'enable_customer_portal', true, 'boolean');
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
