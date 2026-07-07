<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyPhoneLabelsTest extends TestCase
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
            'name' => 'Phone Label Owner',
            'email' => 'phone-label-owner@example.test',
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

    public function test_company_create_and_edit_screens_show_clear_whatsapp_and_phone_labels(): void
    {
        $create = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/create'));

        $create->assertOk()
            ->assertSee('WhatsApp Cep Telefonu')
            ->assertSee('Normal Telefon')
            ->assertSee('🇹🇷 +90')
            ->assertSee('5xx xxx xx xx')
            ->assertDontSee('Telefon / WhatsApp');

        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Telefon Etiket Test A.Ş.',
            'status' => 'active',
            'phone' => '02121231212',
            'mobile' => '05321234567',
        ]);

        $company->companyRoles()->create([
            'tenant_account_id' => $this->tenant->id,
            'role_key' => 'customer',
        ]);

        $edit = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '/edit'));

        $edit->assertOk()
            ->assertSee('WhatsApp Cep Telefonu')
            ->assertSee('Normal Telefon')
            ->assertSee('🇹🇷 +90')
            ->assertSee('0532 123 45 67')
            ->assertDontSee('Telefon / WhatsApp');
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
