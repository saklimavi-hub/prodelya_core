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

class CompanyEditValidationTurkishTerminologyTest extends TestCase
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
            'name' => 'Terminology Owner',
            'email' => 'terminology-owner@example.test',
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
            'legal_name' => 'Terminoloji Cari',
            'status' => 'active',
        ]);
    }

    public function test_edit_validation_surface_keeps_turkish_terms_and_hides_technical_english(): void
    {
        $response = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $this->company->id . '/edit'));

        $response->assertOk()
            ->assertSee('Cari Kartı Düzenle')
            ->assertSee('Tedarikçi')
            ->assertSee('Cari Kart')
            ->assertSee('VKN / TCKN')
            ->assertSee('Vergi Dairesi')
            ->assertSee('İletişim ve Resmi Bilgiler')
            ->assertDontSee('Musteri')
            ->assertDontSee('Tedarikci')
            ->assertDontSee('Company')
            ->assertDontSee('Current Account')
            ->assertDontSee('source_type')
            ->assertDontSee('meta_json');
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
