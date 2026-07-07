<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccountLink;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyEditSupplierSourceDifferentCompanyConflictTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_different_active_company_conflict_message_names_existing_company(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $owner = $this->ownerFor($tenant);
        TenantModule::query()->updateOrCreate(
            ['tenant_account_id' => $tenant->id, 'module_key' => 'current_accounts', 'feature_key' => null],
            ['is_enabled' => true]
        );

        $supplier = Supplier::query()->create([
            'name' => 'Çakışan Kaynak',
            'code' => 'CONFLICT-001',
            'status' => 'active',
        ]);
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        $linkedCompany = $this->createMappedCompany($tenant, 'ABC Tedarik', $supplier->id);
        $targetCompany = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => 'Yeni Hedef Cari',
            'phone' => '02121234567',
            'tax_number' => '1234567890',
            'tax_office' => 'Beşiktaş',
            'status' => 'active',
        ]);
        $targetCompany->companyRoles()->create([
            'tenant_account_id' => $tenant->id,
            'role_key' => 'customer',
        ]);
        app(CurrentAccountSyncService::class)->ensureForCompany($targetCompany->fresh('companyRoles'));

        $this->actingAs($owner, 'web')
            ->followingRedirects()
            ->from($this->tenantUrl($tenant, '/admin/companies/' . $targetCompany->id . '/edit'))
            ->put($this->tenantUrl($tenant, '/admin/companies/' . $targetCompany->id), [
                'identity_type' => 'company',
                'legal_name' => $targetCompany->legal_name,
                'phone' => $targetCompany->phone,
                'tax_number' => $targetCompany->tax_number,
                'tax_office' => $targetCompany->tax_office,
                'status' => 'active',
                'roles' => ['customer', 'supplier'],
                'supplier_id' => $supplier->id,
            ])
            ->assertOk()
            ->assertSee('Bu hazır ürün kaynağı şu Cari Kart ile eşleştirilmiş: ' . $linkedCompany->legal_name . '.')
            ->assertDontSee('Başka yerde kullanılmış');
    }

    private function createMappedCompany(TenantAccount $tenant, string $name, int $supplierId): Company
    {
        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => $name,
            'phone' => '02121234567',
            'tax_number' => '1234567890',
            'tax_office' => 'Şişli',
            'status' => 'active',
        ]);
        $company->companyRoles()->create([
            'tenant_account_id' => $tenant->id,
            'role_key' => 'supplier',
        ]);

        $account = app(CurrentAccountSyncService::class)->ensureForCompany($company->fresh('companyRoles'));
        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $account->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplierId,
            'is_primary' => true,
            'meta_json' => ['linked_via' => 'test'],
        ]);

        return $company;
    }

    private function ownerFor(TenantAccount $tenant): User
    {
        $owner = User::query()->create([
            'name' => 'Different Company Conflict Owner',
            'email' => 'different-company-conflict-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $tenant->id,
            'user_id' => $owner->id,
            'role_id' => Role::query()->where('key', 'tenant_owner')->value('id'),
        ]);

        return $owner;
    }

    private function tenantUrl(TenantAccount $tenant, string $path): string
    {
        return 'http://' . $tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
