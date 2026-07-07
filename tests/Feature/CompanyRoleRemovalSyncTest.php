<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountRole;
use App\Models\CurrentAccountTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use App\Models\WorkForm;
use App\Services\CurrentAccountSyncService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyRoleRemovalSyncTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private TenantAccount $foreignTenant;
    private User $owner;
    private User $foreignOwner;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        $tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();

        $this->owner = User::query()->create([
            'name' => 'Company Role Removal Owner',
            'email' => 'company-role-removal-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'role_id' => $tenantOwnerRole->id,
        ]);

        $this->foreignTenant = TenantAccount::query()->create([
            'name' => 'Foreign Role Removal Tenant',
            'legal_name' => 'Foreign Role Removal Tenant Ltd.',
            'slug' => 'foreign-role-removal-tenant',
            'panel_subdomain' => 'foreign-role-removal-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $this->foreignOwner = User::query()->create([
            'name' => 'Foreign Role Removal Owner',
            'email' => 'foreign-role-removal-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'user_id' => $this->foreignOwner->id,
            'role_id' => $tenantOwnerRole->id,
        ]);

        $this->enableCurrentAccounts();
    }

    public function test_customer_role_removal_syncs_company_and_current_account_badges_and_preserves_supplier_mapping(): void
    {
        [$supplier] = $this->createAccessibleSupplierFixture('Etkin Promosyon', 'ETKIN-RM');
        $company = $this->createCompany('Etkin Karma Cari', ['customer', 'supplier'], $supplier->id);
        $account = $this->linkedAccountForCompany($company);

        $transaction = CurrentAccountTransaction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'transaction_type' => CurrentAccountTransaction::TYPE_OTHER,
            'direction' => CurrentAccountTransaction::DIRECTION_DEBIT,
            'amount' => 1250,
            'currency' => 'TL',
            'transaction_date' => now()->toDateString(),
            'description' => 'Role removal safety transaction',
            'status' => CurrentAccountTransaction::STATUS_OPEN,
        ]);

        $response = $this->actingAs($this->owner, 'web')
            ->put($this->tenantUrl('/admin/companies/' . $company->id), [
                'identity_type' => 'company',
                'legal_name' => $company->legal_name,
                'status' => 'active',
                'roles' => ['supplier'],
                'supplier_id' => $supplier->id,
            ]);

        $response->assertRedirect($this->tenantUrl('/admin/companies/' . $company->id));

        $company = $company->fresh('companyRoles');
        $account = $account->fresh(['roles', 'links']);

        $this->assertFalse($company->hasRole('customer'));
        $this->assertTrue($company->hasRole('supplier'));
        $this->assertFalse($account->hasRole(CurrentAccountRole::ROLE_CUSTOMER));
        $this->assertTrue($account->hasRole(CurrentAccountRole::ROLE_SUPPLIER));
        $this->assertSame(0, $account->roles->where('role', CurrentAccountRole::ROLE_CUSTOMER)->count());
        $this->assertSame(1, $account->roles->where('role', CurrentAccountRole::ROLE_SUPPLIER)->count());

        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);

        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
        ]);

        $this->assertDatabaseHas('current_account_transactions', [
            'id' => $transaction->id,
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
        ]);

        $show = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id));

        $show->assertOk()
            ->assertDontSee('<span class="pd-badge pd-badge-green">Müşteri</span>', false)
            ->assertSee('<span class="pd-badge pd-badge-blue">Tedarikçi</span>', false);

        $index = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies'));

        $index->assertOk()
            ->assertSee($company->legal_name)
            ->assertSee('<span class="pd-badge pd-badge-blue">Tedarikçi</span>', false);
    }

    public function test_supplier_role_removal_cleans_supplier_mapping_links_without_touching_company_link(): void
    {
        [$supplier] = $this->createAccessibleSupplierFixture('İlpen', 'ILPEN-RM');
        $company = $this->createCompany('Supplier Removal Cari', ['supplier'], $supplier->id);
        $account = $this->linkedAccountForCompany($company);

        $this->actingAs($this->owner, 'web')
            ->put($this->tenantUrl('/admin/companies/' . $company->id), [
                'identity_type' => 'company',
                'legal_name' => $company->legal_name,
                'status' => 'active',
                'roles' => ['other'],
            ])
            ->assertRedirect($this->tenantUrl('/admin/companies/' . $company->id));

        $account = $account->fresh(['roles', 'links']);

        $this->assertFalse($account->hasRole(CurrentAccountRole::ROLE_SUPPLIER));
        $this->assertTrue($account->hasRole(CurrentAccountRole::ROLE_OTHER));

        $this->assertDatabaseMissing('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);

        $this->assertDatabaseMissing('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
        ]);

        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'link_type' => CurrentAccountLink::LINK_COMPANY,
            'link_id' => $company->id,
        ]);
    }

    public function test_print_fason_role_removal_hides_company_from_production_assignment_list(): void
    {
        $company = $this->createCompany('Fason Listeden Kalksin', ['print_fason']);
        $production = $this->createProduction('SP-FASON-RM-001');

        $before = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id . '?tab=islemler'));

        $before->assertOk()->assertSee($company->legal_name);

        $this->actingAs($this->owner, 'web')
            ->put($this->tenantUrl('/admin/companies/' . $company->id), [
                'identity_type' => 'company',
                'legal_name' => $company->legal_name,
                'status' => 'active',
                'roles' => ['other'],
            ])
            ->assertRedirect($this->tenantUrl('/admin/companies/' . $company->id));

        $after = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id . '?tab=islemler'));

        $after->assertOk()->assertDontSee($company->legal_name);
    }

    public function test_role_removal_stays_in_tenant_scope_and_does_not_duplicate_remaining_roles(): void
    {
        $localCompany = $this->createCompany('Tenant Scope Local Cari', ['customer', 'supplier']);
        $localAccount = $this->linkedAccountForCompany($localCompany);

        $foreignCompany = Company::query()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'legal_name' => 'Foreign Scope Cari',
            'status' => 'active',
        ]);

        $foreignCompany->companyRoles()->createMany([
            [
                'tenant_account_id' => $this->foreignTenant->id,
                'role_key' => 'customer',
            ],
            [
                'tenant_account_id' => $this->foreignTenant->id,
                'role_key' => 'supplier',
            ],
        ]);

        $foreignAccount = app(CurrentAccountSyncService::class)->ensureForCompany($foreignCompany->fresh('companyRoles'));

        $this->actingAs($this->owner, 'web')
            ->put($this->tenantUrl('/admin/companies/' . $localCompany->id), [
                'identity_type' => 'company',
                'legal_name' => $localCompany->legal_name,
                'status' => 'active',
                'roles' => ['supplier'],
            ])
            ->assertRedirect($this->tenantUrl('/admin/companies/' . $localCompany->id));

        $localAccount = $localAccount->fresh('roles');
        $foreignAccount = $foreignAccount->fresh('roles');

        $this->assertSame(1, $localAccount->roles->where('role', CurrentAccountRole::ROLE_SUPPLIER)->count());
        $this->assertSame(0, $localAccount->roles->where('role', CurrentAccountRole::ROLE_CUSTOMER)->count());
        $this->assertTrue($foreignAccount->hasRole(CurrentAccountRole::ROLE_CUSTOMER));
        $this->assertTrue($foreignAccount->hasRole(CurrentAccountRole::ROLE_SUPPLIER));
    }

    private function createAccessibleSupplierFixture(string $name, string $code): array
    {
        $supplier = Supplier::query()->firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'status' => 'active',
            ]
        );

        SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => $name . ' Kaynağı',
            'url' => 'https://example.test/' . strtolower($code),
            'status' => 'active',
        ]);

        TenantSupplierAccess::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'supplier_id' => $supplier->id,
            ],
            [
                'is_active' => true,
                'can_view_products' => true,
                'can_request_purchase' => true,
            ]
        );

        return [$supplier];
    }

    private function createCompany(string $name, array $roles, ?int $supplierId = null): Company
    {
        $payload = [
            'identity_type' => 'company',
            'legal_name' => $name,
            'status' => 'active',
            'roles' => $roles,
        ];

        if ($supplierId) {
            $payload['supplier_id'] = $supplierId;
        }

        $this->actingAs($this->owner, 'web')
            ->post($this->tenantUrl('/admin/companies'), $payload)
            ->assertRedirect();

        return Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', $name)
            ->latest('id')
            ->firstOrFail();
    }

    private function linkedAccountForCompany(Company $company): CurrentAccount
    {
        $link = CurrentAccountLink::query()
            ->where('tenant_account_id', $company->tenant_account_id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('link_id', $company->id)
            ->where('is_primary', true)
            ->firstOrFail();

        return CurrentAccount::query()
            ->where('tenant_account_id', $company->tenant_account_id)
            ->findOrFail($link->current_account_id);
    }

    private function createProduction(string $documentNumber): OrderItemPrintProduction
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $documentNumber,
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'created_by' => $this->owner->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Role Removal Production Product',
            'product_code' => 'ROLE-RM-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'catalog_source' => 'tenant_catalog',
            'has_print' => true,
            'status' => 'pending',
        ]);

        $print = OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_quantity' => 100,
            'status' => 'draft',
        ]);

        /** @var WorkForm $workForm */
        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->owner)->first();

        return $print->fresh(['production'])->production->fresh([
            'productionCompany',
            'orderItemPrint.subcontractorCompany',
            'order.customer',
            'workForm',
        ]);
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

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
