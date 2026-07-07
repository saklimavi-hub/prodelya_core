<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountRole;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyShowDuplicateCheckTabTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_duplicate_candidate_company_shows_duplicate_check_tab_with_user_facing_labels(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $supplier = Supplier::query()->create([
            'name' => 'Etkin Promosyon',
            'code' => 'ETKIN-D1',
            'status' => 'active',
        ]);

        $access = TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        [$canonicalAccount, $canonicalCompany] = $this->createSupplierCompany($tenant, 'Etkin Promosyon', 'Etkin');
        [, $duplicateCompany] = $this->createSupplierCompany($tenant, 'Etkin Promosyon', 'Etkin Tekrar');

        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $canonicalAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
            'is_primary' => true,
        ]);

        CurrentAccountLink::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $canonicalAccount->id,
            'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
            'link_id' => $access->id,
            'is_primary' => true,
        ]);

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.companies.show', ['company' => $duplicateCompany, 'tab' => 'benzer-cari']));

        $response->assertOk()
            ->assertSee('Benzer Cari Kontrolü')
            ->assertSee('Ana Cari Kart')
            ->assertSee('Benzer Cari')
            ->assertSee('Çift kayıt adayı')
            ->assertDontSee('canonical')
            ->assertDontSee('supplier_id')
            ->assertDontSee('current_account_id');
    }

    private function createSupplierCompany(TenantAccount $tenant, string $legalName, string $shortName): array
    {
        $company = Company::query()->create([
            'tenant_account_id' => $tenant->id,
            'legal_name' => $legalName,
            'short_name' => $shortName,
            'status' => 'active',
        ]);

        $company->companyRoles()->create([
            'tenant_account_id' => $tenant->id,
            'role_key' => 'supplier',
        ]);

        $account = app(CurrentAccountSyncService::class)->ensureForCompany($company->fresh('companyRoles'));

        return [$account->fresh(), $company->fresh()];
    }
}
