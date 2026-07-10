<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyPhoneDisplayFormatTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $owner;
    private Role $tenantOwnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->owner = User::query()->create([
            'name' => 'Phone Display Owner',
            'email' => 'phone-display-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'current_accounts',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );
    }

    public function test_company_list_and_detail_show_formatted_whatsapp_phone_and_safe_link(): void
    {
        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Display Phone Company',
            'status' => 'active',
            'phone' => '02121231212',
            'mobile' => '05321234567',
        ]);

        $company->companyRoles()->create([
            'tenant_account_id' => $this->tenant->id,
            'role_key' => 'customer',
        ]);

        $index = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies'));

        $index->assertOk()
            ->assertSee('WhatsApp:')
            ->assertSee('0532 123 45 67')
            ->assertSee('Telefon:')
            ->assertSee('0212 123 12 12')
            ->assertSee('https://wa.me/905321234567', false);

        $show = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id));

        $show->assertOk()
            ->assertSee('WhatsApp:')
            ->assertSee('0532 123 45 67')
            ->assertSee('Telefon:')
            ->assertSee('0212 123 12 12')
            ->assertDontSee('https://wa.me/905321234567', false);
    }

    public function test_invalid_mobile_does_not_render_whatsapp_link(): void
    {
        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Invalid Whatsapp Company',
            'status' => 'active',
            'mobile' => 'abc',
        ]);

        $company->companyRoles()->create([
            'tenant_account_id' => $this->tenant->id,
            'role_key' => 'customer',
        ]);

        $show = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id));

        $show->assertOk()
            ->assertDontSee('https://wa.me/', false);
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
