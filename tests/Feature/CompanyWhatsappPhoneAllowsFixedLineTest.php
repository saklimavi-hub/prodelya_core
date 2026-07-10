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

class CompanyWhatsappPhoneAllowsFixedLineTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_company_whatsapp_phone_allows_fixed_line(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $ownerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $owner = User::query()->create([
            'name' => 'Fixed Line Owner',
            'email' => 'fixed-line-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $owner->id,
            'role_id' => $ownerRole->id,
        ]);

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'module_key' => 'current_accounts',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Fixed Line WhatsApp Company',
            'status' => 'active',
            'mobile' => '02125018233',
        ]);

        $company->companyRoles()->create([
            'tenant_account_id' => $tenant->id,
            'role_key' => 'customer',
        ]);

        $response = $this->actingAs($owner, 'web')
            ->get('http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . '/admin/companies');

        $response->assertOk()
            ->assertSee('0212 501 82 33')
            ->assertSee('https://wa.me/902125018233', false);
    }
}
