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

class CompanyEditSupplierRoleValidationDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $owner;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->owner = User::query()->create([
            'name' => 'Validation Detail Owner',
            'email' => 'validation-detail-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'role_id' => Role::query()->where('key', 'tenant_owner')->value('id'),
        ]);

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'current_accounts',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        $this->company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Validasyon Detay Cari',
            'status' => 'active',
        ]);

        $this->company->companyRoles()->create([
            'tenant_account_id' => $this->tenant->id,
            'role_key' => 'customer',
        ]);
    }

    public function test_edit_form_shows_top_summary_field_error_and_record_summary_details(): void
    {
        $this->actingAs($this->owner, 'web')
            ->followingRedirects()
            ->from($this->tenantUrl('/admin/companies/' . $this->company->id . '/edit'))
            ->put($this->tenantUrl('/admin/companies/' . $this->company->id), [
                'identity_type' => 'company',
                'legal_name' => $this->company->legal_name,
                'tax_number' => '123',
                'status' => 'active',
                'roles' => ['customer', 'supplier'],
            ])
            ->assertOk()
            ->assertSee('Lütfen aşağıdaki alanları kontrol edin:')
            ->assertSee('İletişim ve Resmi Bilgiler: VKN / TCKN alanı eksik veya hatalı.')
            ->assertSee('VKN / TCKN 10 veya 11 haneli rakamlardan oluşmalıdır.')
            ->assertSee('Eksik bilgi var')
            ->assertSee('Eksik alan özeti')
            ->assertSee('Vergi bilgisi: Vergi Dairesi eksik')
            ->assertSee('İletişim durumu: E-posta veya telefon bilgisi eksik')
            ->assertDontSee('Formu kaydetmeden önce işaretli alanları kontrol ediniz.');
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
