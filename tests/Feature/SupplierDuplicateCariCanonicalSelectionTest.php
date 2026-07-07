<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccountTransaction;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Services\CurrentAccountSyncService;
use App\Services\TenantSupplierCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierDuplicateCariCanonicalSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_financial_history_wins_canonical_selection(): void
    {
        $tenant = $this->makeTenant('selection-finance');
        $supplier = $this->makeSupplierWithAccess($tenant, 'Seçim Finans Tedarikçi', 'SEL-FIN-001');

        $financial = $this->createSupplierCompany($tenant, 'Seçim Finans Tedarikçi', 'Finanslı');
        $empty = $this->createSupplierCompany($tenant, 'Seçim Finans Tedarikçi', 'Boş');
        $financialAccount = app(CurrentAccountSyncService::class)->ensureForCompany($financial->fresh('companyRoles'));
        app(CurrentAccountSyncService::class)->ensureForCompany($empty->fresh('companyRoles'));

        CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $tenant->id,
            'current_account_id' => $financialAccount->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_SUPPLIER_DEBIT,
            'source_type' => 'manual',
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 100,
            'currency' => 'TRY',
            'transaction_date' => now()->toDateString(),
            'status' => CurrentAccountTransaction::STATUS_OPEN,
            'description' => 'Finans önceliği',
        ]);

        $audit = app(TenantSupplierCurrentAccountSyncService::class)->auditDuplicateSupplierCaris($tenant, $supplier);
        $this->assertSame($financial->id, data_get($audit, 'canonical_candidate.company.id'));
    }

    public function test_richer_and_older_record_wins_when_financial_history_is_missing(): void
    {
        $tenant = $this->makeTenant('selection-rich');
        $supplier = $this->makeSupplierWithAccess($tenant, 'Seçim Bilgi Tedarikçi', 'SEL-RICH-001');

        $older = $this->createSupplierCompany($tenant, 'Seçim Bilgi Tedarikçi', 'Eski Ana', [
            'phone' => '02121234567',
            'email' => 'eski@example.test',
            'tax_number' => '1234567890',
            'tax_office' => 'Şişli',
        ]);
        $newer = $this->createSupplierCompany($tenant, 'Seçim Bilgi Tedarikçi', 'Yeni Kopya');
        $older->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->save();
        app(CurrentAccountSyncService::class)->ensureForCompany($older->fresh('companyRoles'));
        app(CurrentAccountSyncService::class)->ensureForCompany($newer->fresh('companyRoles'));

        $audit = app(TenantSupplierCurrentAccountSyncService::class)->auditDuplicateSupplierCaris($tenant, $supplier);
        $this->assertSame($older->id, data_get($audit, 'canonical_candidate.company.id'));
        $this->assertContains('Daha eski ve ana kayıt görünümündeki cari önceliklendirildi.', data_get($audit, 'canonical_candidate.selection_reasons'));
    }

    private function makeTenant(string $slug): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => 'Selection Tenant',
            'legal_name' => 'Selection Tenant Ltd. Şti.',
            'slug' => $slug,
            'panel_subdomain' => $slug,
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function makeSupplierWithAccess(TenantAccount $tenant, string $name, string $code): Supplier
    {
        $supplier = Supplier::query()->create([
            'name' => $name,
            'code' => $code,
            'status' => 'active',
        ]);
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
        ]);

        return $supplier;
    }

    private function createSupplierCompany(TenantAccount $tenant, string $legalName, string $shortName, array $overrides = []): Company
    {
        $company = Company::query()->create(array_merge([
            'tenant_account_id' => $tenant->id,
            'legal_name' => $legalName,
            'short_name' => $shortName,
            'status' => 'active',
        ], $overrides));
        $company->companyRoles()->create([
            'tenant_account_id' => $tenant->id,
            'role_key' => 'supplier',
        ]);

        return $company;
    }
}
