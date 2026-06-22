<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountRole;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Services\CurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentAccountLinkManagementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
    }

    public function test_supplier_link_area_visibility_and_manual_link_flow_work_safely(): void
    {
        $supplierAccount = $this->createAccount('Tedarikci Cari Link', [
            CurrentAccountRole::ROLE_SUPPLIER,
            CurrentAccountRole::ROLE_CUSTOMER,
        ]);
        $nonSupplierAccount = $this->createAccount('Musteri Cari Link', [CurrentAccountRole::ROLE_CUSTOMER]);

        $supplier = Supplier::query()->create([
            'name' => 'Etkin',
            'code' => 'ETKIN-001',
            'contact_email' => 'secret-etkin@example.test',
            'contact_phone' => '02120001122',
            'status' => 'active',
        ]);

        $source = SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_name' => 'Etkin Feed',
            'source_type' => 'xml',
            'url' => 'https://example.test/etkin.xml',
            'status' => 'active',
            'is_visible_in_product_data_hub' => true,
            'config' => ['token' => 'hidden'],
        ]);

        $access = TenantSupplierAccess::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
            'can_view_products' => true,
            'can_request_purchase' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
            'export_allowed' => true,
            'meta' => ['secret' => 'hidden'],
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.show', $supplierAccount))
            ->assertOk()
            ->assertSee('Ürün/Data Kaynağı Bağlantısı')
            ->assertSee('Supplier Bağla');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.show', $nonSupplierAccount))
            ->assertOk()
            ->assertDontSee('Supplier Bağla');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.current-accounts.show', $supplierAccount))
            ->post(route('admin.current-accounts.supplier-link.store', $supplierAccount), [
                'supplier_id' => $supplier->id,
            ])
            ->assertRedirect(route('admin.current-accounts.show', $supplierAccount));

        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $supplierAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);

        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $supplierAccount->id,
            'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
            'link_id' => $access->id,
        ]);

        $show = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.show', $supplierAccount));

        $show->assertOk();
        $show->assertSee('Ürün/Data Kaynağı');
        $show->assertSee('Tenant Tedarikçi Erişimi');
        $show->assertDontSee('secret-etkin@example.test');
        $show->assertDontSee('ETKIN-001');
        $show->assertDontSee('etkin.xml');
        $show->assertDontSee('group_code', false);
        $show->assertDontSee('file_path', false);
        $show->assertDontSee('physical_path', false);

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'name' => 'Etkin',
        ]);
        $this->assertDatabaseHas('supplier_sources', [
            'id' => $source->id,
            'supplier_id' => $supplier->id,
        ]);
    }

    public function test_same_supplier_cannot_be_linked_to_second_current_account_in_same_tenant_and_can_be_detached(): void
    {
        $first = $this->createAccount('Supplier Cari 1', [CurrentAccountRole::ROLE_SUPPLIER]);
        $second = $this->createAccount('Supplier Cari 2', [CurrentAccountRole::ROLE_SUPPLIER]);

        $supplier = Supplier::query()->create([
            'name' => 'Ilpen',
            'code' => 'ILPEN-001',
            'status' => 'active',
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.current-accounts.supplier-link.store', $first), [
                'supplier_id' => $supplier->id,
            ])
            ->assertRedirect(route('admin.current-accounts.show', $first));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.current-accounts.show', $second))
            ->post(route('admin.current-accounts.supplier-link.store', $second), [
                'supplier_id' => $supplier->id,
            ])
            ->assertRedirect(route('admin.current-accounts.show', $second))
            ->assertSessionHasErrors('supplier_id');

        $this->assertDatabaseMissing('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $second->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->delete(route('admin.current-accounts.supplier-link.destroy', $first))
            ->assertRedirect(route('admin.current-accounts.show', $first));

        $this->assertDatabaseMissing('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $first->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);
    }

    public function test_status_actions_update_without_physical_delete_and_archived_filter_works(): void
    {
        $account = $this->createAccount('Durumlu Cari', [CurrentAccountRole::ROLE_CUSTOMER], true);
        $companyLink = $account->links()->where('link_type', CurrentAccountLink::LINK_COMPANY)->firstOrFail();
        $company = Company::query()->findOrFail($companyLink->link_id);

        foreach ([
            CurrentAccount::STATUS_PASSIVE,
            CurrentAccount::STATUS_BLOCKED,
            CurrentAccount::STATUS_ARCHIVED,
            CurrentAccount::STATUS_ACTIVE,
        ] as $status) {
            $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->patch(route('admin.current-accounts.update-status', $account), [
                    'status' => $status,
                ])
                ->assertRedirect(route('admin.companies.show', $company));

            $this->assertSame($status, $account->fresh()->status);
        }

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.current-accounts.update-status', $account), [
                'status' => CurrentAccount::STATUS_ARCHIVED,
            ])
            ->assertRedirect(route('admin.companies.show', $company));

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.current-accounts.index', ['status' => CurrentAccount::STATUS_ARCHIVED]));

        $response->assertOk();
        $response->assertSee('Durumlu Cari');

        $blockedResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.current-accounts.update-status', $account), [
                'status' => CurrentAccount::STATUS_BLOCKED,
            ]);

        $blockedResponse->assertRedirect(route('admin.companies.show', $company));

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.companies.show', $company))
            ->assertOk()
            ->assertSee('Bloklu');

        $this->assertSame(1, CurrentAccount::query()->whereKey($account->id)->count());
    }

    public function test_foreign_tenant_link_management_is_forbidden(): void
    {
        $foreignTenant = TenantAccount::query()->create([
            'name' => 'Foreign Link Tenant',
            'legal_name' => 'Foreign Link Tenant Ltd.',
            'slug' => 'foreign-link-tenant',
            'panel_subdomain' => 'foreign-link-tenant',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $foreignAccount = CurrentAccount::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'display_name' => 'Foreign Current Account',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Akdeniz',
            'code' => 'AKD-001',
            'status' => 'active',
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.current-accounts.supplier-link.store', $foreignAccount), [
                'supplier_id' => $supplier->id,
            ])
            ->assertForbidden();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->delete(route('admin.current-accounts.supplier-link.destroy', $foreignAccount))
            ->assertForbidden();
    }

    private function createAccount(string $displayName, array $roles, bool $withCompany = false): CurrentAccount
    {
        $account = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => $displayName,
            'legal_name' => $displayName . ' Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        app(CurrentAccountSyncService::class)->syncRoles($account, $roles);

        if ($withCompany) {
            app(CurrentAccountSyncService::class)->ensureCompanyForCurrentAccount($account, $roles);
        }

        return $account->fresh(['roles', 'links']);
    }
}
