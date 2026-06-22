<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Supplier;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\CurrentAccountSyncService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentAccountUiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
    }

    public function test_index_lists_only_tenant_accounts_and_role_filters_work(): void
    {
        $customerAccount = $this->createCurrentAccount('Musteri Cari A', [CurrentAccountRole::ROLE_CUSTOMER]);
        $supplierAccount = $this->createCurrentAccount('Tedarikci Cari A', [
            CurrentAccountRole::ROLE_SUPPLIER,
            CurrentAccountRole::ROLE_CARRIER,
        ]);

        $otherTenant = $this->createOtherTenant();
        CurrentAccount::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'display_name' => 'Yabanci Cari',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.index'));

        $response->assertOk();
        $response->assertSee('Cari Kartlar');
        $response->assertSee($customerAccount->display_name);
        $response->assertSee($supplierAccount->display_name);
        $response->assertDontSee('Yabanci Cari');
        $response->assertSee(route('admin.current-accounts.index'), false);
        $response->assertSee(route('admin.companies.create'), false);
        $response->assertDontSee('Bakiye');
        $response->assertDontSee('Borç');
        $response->assertDontSee('Alacak');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('file_path', false);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.index', ['role' => CurrentAccountRole::ROLE_CUSTOMER]))
            ->assertOk()
            ->assertSee($customerAccount->display_name)
            ->assertDontSee($supplierAccount->display_name);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.index', ['role' => CurrentAccountRole::ROLE_SUPPLIER]))
            ->assertOk()
            ->assertSee($supplierAccount->display_name)
            ->assertDontSee($customerAccount->display_name);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.index', ['role' => CurrentAccountRole::ROLE_CARRIER]))
            ->assertOk()
            ->assertSee($supplierAccount->display_name)
            ->assertDontSee($customerAccount->display_name);
    }

    public function test_create_screen_redirects_to_company_form_and_store_still_supports_multi_role_account_mapping(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.create'))
            ->assertRedirect(route('admin.companies.create'));

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.current-accounts.store'), [
                'display_name' => 'Yeni Cari Multi',
                'legal_name' => 'Yeni Cari Multi San. ve Tic. Ltd. Şti.',
                'account_code' => 'CA-UI-001',
                'status' => CurrentAccount::STATUS_ACTIVE,
                'risk_status' => 'medium',
                'phone' => '02125550101',
                'email' => 'yeni-cari@example.test',
                'default_currency' => 'TRY',
                'payment_terms_days' => 15,
                'risk_limit' => 25000,
                'roles' => [
                    CurrentAccountRole::ROLE_CUSTOMER,
                    CurrentAccountRole::ROLE_SUBCONTRACTOR,
                    CurrentAccountRole::ROLE_SUBCONTRACTOR,
                ],
            ]);

        $account = CurrentAccount::query()->where('display_name', 'Yeni Cari Multi')->firstOrFail();
        $companyLink = CurrentAccountLink::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('current_account_id', $account->id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->firstOrFail();
        $company = Company::query()->findOrFail($companyLink->link_id);

        $response->assertRedirect(route('admin.companies.show', $company));

        $this->assertSame(2, $account->roles()->count());
        $this->assertTrue($account->fresh()->isCustomer());
        $this->assertTrue($account->fresh()->isSubcontractor());

        $this->assertSame('Yeni Cari Multi San. ve Tic. Ltd. Şti.', $company->legal_name);
        $this->assertTrue($company->hasRole('customer'));
        $this->assertTrue($company->hasRole('print_fason'));
    }

    public function test_supplier_only_account_can_be_created_without_creating_global_supplier_or_company_link(): void
    {
        $existingSupplierCount = Supplier::query()->count();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.current-accounts.store'), [
                'display_name' => 'Tenant Tedarikçi Kartı',
                'status' => CurrentAccount::STATUS_ACTIVE,
                'roles' => [CurrentAccountRole::ROLE_SUPPLIER],
            ]);

        $account = CurrentAccount::query()->where('display_name', 'Tenant Tedarikçi Kartı')->firstOrFail();

        $response->assertRedirect(route('admin.current-accounts.show', $account));
        $this->assertTrue($account->fresh()->isSupplier());
        $this->assertSame(0, $account->links()->where('link_type', CurrentAccountLink::LINK_COMPANY)->count());
        $this->assertSame($existingSupplierCount, Supplier::query()->count());
    }

    public function test_show_and_edit_render_links_safely_and_sync_roles_to_company(): void
    {
        $account = $this->createCurrentAccount('Linked Cari', [CurrentAccountRole::ROLE_CUSTOMER], true);
        $supplier = Supplier::query()->create([
            'name' => 'Global PDH Supplier',
            'code' => 'SUP-RAW-001',
            'contact_email' => 'raw-hidden@example.test',
            'contact_phone' => '02120000000',
            'status' => 'active',
        ]);

        CurrentAccountLink::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $account->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
            'is_primary' => true,
            'meta_json' => ['supplier_code' => $supplier->code],
        ]);

        $show = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.show', $account));

        $companyLink = $account->links()->where('link_type', CurrentAccountLink::LINK_COMPANY)->firstOrFail();
        $company = Company::query()->findOrFail($companyLink->link_id);

        $show->assertRedirect(route('admin.companies.show', $company));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.edit', $account))
            ->assertRedirect(route('admin.companies.edit', $company));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.current-accounts.update', $account), [
                'display_name' => 'Linked Cari Guncel',
                'legal_name' => 'Linked Cari Guncel Ltd.',
                'status' => CurrentAccount::STATUS_ACTIVE,
                'phone' => '03120001122',
                'roles' => [
                    CurrentAccountRole::ROLE_CUSTOMER,
                    CurrentAccountRole::ROLE_CARRIER,
                ],
            ])
            ->assertRedirect(route('admin.companies.show', $company));

        $account = $account->fresh(['roles', 'links']);
        $this->assertSame('Linked Cari Guncel', $account->display_name);
        $this->assertTrue($account->isCustomer());
        $this->assertTrue($account->isCarrier());
        $this->assertSame(2, $account->roles()->count());

        $company = Company::query()->findOrFail($companyLink->link_id);

        $this->assertSame('Linked Cari Guncel Ltd.', $company->legal_name);
        $this->assertTrue($company->hasRole('customer'));
        $this->assertTrue($company->hasRole('delivery_partner'));
    }

    public function test_foreign_tenant_account_is_forbidden_and_public_tracking_does_not_render_current_account_data(): void
    {
        $otherTenant = $this->createOtherTenant();
        $foreignAccount = CurrentAccount::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'display_name' => 'Yabanci Tenant Cari',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.show', $foreignAccount))
            ->assertForbidden();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.edit', $foreignAccount))
            ->assertForbidden();

        $secretAccount = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => 'GIZLI-CARI-PUBLIC-TEST',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        $workForm = $this->createPublicTrackingWorkForm();

        $publicResponse = $this->get(route('public.work-forms.track', $workForm->public_tracking_token));

        $publicResponse->assertOk();
        $publicResponse->assertDontSee($secretAccount->display_name);
    }

    private function createCurrentAccount(string $displayName, array $roles = [], bool $ensureCompany = false): CurrentAccount
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
            'email' => fake()->unique()->safeEmail(),
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, $roles);

        if ($ensureCompany) {
            app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, $roles);
        }

        return $account->fresh(['roles', 'links']);
    }

    private function createOtherTenant(): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => 'Other Tenant UI',
            'legal_name' => 'Other Tenant UI Ltd.',
            'slug' => 'other-tenant-ui',
            'panel_subdomain' => 'other-tenant-ui',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function createPublicTrackingWorkForm()
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-CA-UI-' . fake()->unique()->numerify('####'),
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Current Account Public Test Product',
            'product_code' => 'CA-PUBLIC-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'catalog_source' => 'tenant_catalog',
            'has_print' => false,
            'status' => 'pending',
        ]);

        $workForm = app(WorkFormCreationService::class)
            ->createForOrder($order, $this->adminUser)
            ->first();

        $this->assertNotNull($workForm);

        return $workForm->fresh();
    }
}
