<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountRole;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class CurrentAccountCoreTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $adminUser;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
    }

    public function test_current_account_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('current_accounts'));
        $this->assertTrue(Schema::hasTable('current_account_roles'));
        $this->assertTrue(Schema::hasTable('current_account_links'));
    }

    public function test_models_can_be_created(): void
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => 'Test Cari',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        $role = CurrentAccountRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'role' => CurrentAccountRole::ROLE_CUSTOMER,
            'status' => CurrentAccountRole::STATUS_ACTIVE,
        ]);

        $link = CurrentAccountLink::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'link_type' => CurrentAccountLink::LINK_COMPANY,
            'link_id' => 999,
            'is_primary' => true,
        ]);

        $this->assertSame('Test Cari', $account->safeDisplayName());
        $this->assertSame('Müşteri', $role->safeRoleLabel());
        $this->assertSame('Firma', $link->safeLinkLabel());
    }

    public function test_ensure_for_company_creates_account_link_and_roles_without_duplicates(): void
    {
        $company = $this->createCompanyWithRoles(['customer', 'print_fason']);
        $service = app(CurrentAccountSyncService::class);

        $first = $service->ensureForCompany($company);
        $second = $service->ensureForCompany($company->fresh('companyRoles'));

        $this->assertSame($first->id, $second->id);
        $this->assertSame($company->tenant_account_id, $first->tenant_account_id);
        $this->assertSame($company->legal_name, $first->legal_name);
        $this->assertSame($company->tax_number, $first->tax_number);
        $this->assertSame(1, CurrentAccount::query()->count());
        $this->assertSame(1, CurrentAccountLink::query()
            ->where('tenant_account_id', $company->tenant_account_id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('link_id', $company->id)
            ->count());
        $this->assertTrue($first->fresh()->isCustomer());
        $this->assertTrue($first->fresh()->isSubcontractor());
        $this->assertSame(2, $first->fresh()->roles()->count());
    }

    public function test_company_can_receive_multiple_roles_without_duplicate_role_rows(): void
    {
        $company = $this->createCompanyWithRoles(['customer', 'supplier', 'delivery_partner', 'customer']);
        $service = app(CurrentAccountSyncService::class);

        $account = $service->ensureForCompany($company);

        $this->assertTrue($account->fresh()->isCustomer());
        $this->assertTrue($account->fresh()->isSupplier());
        $this->assertTrue($account->fresh()->isCarrier());
        $this->assertSame(3, $account->fresh()->roles()->count());
    }

    public function test_company_from_another_tenant_cannot_link_to_foreign_current_account(): void
    {
        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Tenant',
            'legal_name' => 'Other Tenant Ltd.',
            'slug' => 'other-tenant',
            'panel_subdomain' => 'other-tenant',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $foreignCompany = Company::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'legal_name' => 'Foreign Company',
            'status' => 'active',
        ]);

        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => 'Tenant A Cari',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(CurrentAccountSyncService::class)->linkCompany($account, $foreignCompany);
    }

    public function test_supplier_and_existing_operational_links_remain_unchanged(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Global Supplier',
            'code' => 'GLOBAL-SUP',
            'status' => 'active',
        ]);

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_name' => 'Global Source',
            'source_type' => 'xml',
            'url' => 'https://example.test/feed.xml',
            'status' => 'active',
            'is_visible_in_product_data_hub' => true,
            'config' => ['profile_key' => 'CUSTOM'],
        ]);

        $customer = $this->createCompanyWithRoles(['customer']);
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-CA-0001',
            'customer_company_id' => $customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $payment = OrderPayment::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'customer_company_id' => $customer->id,
            'payment_type' => OrderPayment::TYPE_COLLECTION,
            'amount' => 100,
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        app(CurrentAccountSyncService::class)->ensureForCompany($customer);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'customer_company_id' => $customer->id,
        ]);
        $this->assertDatabaseHas('order_payments', [
            'id' => $payment->id,
            'customer_company_id' => $customer->id,
        ]);
        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'name' => 'Global Supplier',
        ]);
        $this->assertDatabaseHas('supplier_sources', [
            'id' => $source->id,
            'supplier_id' => $supplier->id,
        ]);
    }

    public function test_sync_command_supports_dry_run_and_real_run(): void
    {
        $company = $this->createCompanyWithRoles(['customer', 'delivery_partner']);

        $this->artisan('prodelya:sync-current-accounts', [
            '--tenant' => $this->tenant->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, CurrentAccount::query()->count());

        $this->artisan('prodelya:sync-current-accounts', [
            '--tenant' => $this->tenant->id,
        ])->assertExitCode(0);

        $link = CurrentAccountLink::query()
            ->where('tenant_account_id', $company->tenant_account_id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('link_id', $company->id)
            ->firstOrFail();

        $account = CurrentAccount::query()->findOrFail($link->current_account_id);

        $this->assertSame($company->tenant_account_id, $account->tenant_account_id);
        $this->assertTrue($account->fresh()->isCustomer());
        $this->assertTrue($account->fresh()->isCarrier());
    }

    private function createCompanyWithRoles(array $roles): Company
    {
        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Cari Test Şirketi ' . fake()->unique()->company(),
            'short_name' => 'Cari Test',
            'tax_office' => 'Merkez',
            'tax_number' => (string) fake()->unique()->numerify('##########'),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '02125550101',
            'mobile' => '05550001122',
            'website' => 'https://example.test',
            'status' => 'active',
            'risk_status' => 'low',
            'notes' => 'Cari test notu',
        ]);

        foreach (collect($roles)->unique()->values() as $role) {
            CompanyRole::query()->create([
                'tenant_account_id' => $this->tenant->id,
                'company_id' => $company->id,
                'role_key' => $role,
            ]);
        }

        return $company->fresh('companyRoles');
    }
}
