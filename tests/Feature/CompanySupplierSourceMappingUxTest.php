<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\CurrentAccountLink;
use App\Models\CurrentAccountRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProcurement;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CurrentAccountSyncService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySupplierSourceMappingUxTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private TenantAccount $foreignTenant;
    private User $owner;
    private User $foreignOwner;
    private Role $tenantOwnerRole;
    private Company $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        $this->owner = User::query()->create([
            'name' => 'Supplier Mapping Owner',
            'email' => 'supplier-mapping-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        $this->foreignTenant = TenantAccount::query()->create([
            'name' => 'Foreign Supplier Mapping Tenant',
            'legal_name' => 'Foreign Supplier Mapping Tenant Ltd.',
            'slug' => 'foreign-supplier-mapping-tenant',
            'panel_subdomain' => 'foreign-supplier-mapping-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $this->foreignOwner = User::query()->create([
            'name' => 'Foreign Supplier Mapping Owner',
            'email' => 'foreign-supplier-mapping-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'user_id' => $this->foreignOwner->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        $this->enableCurrentAccounts();
    }

    public function test_company_create_and_edit_surfaces_supplier_mapping_only_for_supplier_flow(): void
    {
        [$supplier, $source] = $this->createAccessibleSupplierFixture('Etkin Promosyon', 'ETKIN');

        $invalid = $this->actingAs($this->owner, 'web')
            ->from($this->tenantUrl('/admin/companies/create'))
            ->post($this->tenantUrl('/admin/companies'), [
                'identity_type' => 'company',
                'legal_name' => '',
                'status' => 'active',
                'roles' => ['supplier'],
                'supplier_id' => $supplier->id,
            ]);

        $invalid->assertRedirect($this->tenantUrl('/admin/companies/create'))
            ->assertSessionHasErrors('legal_name');

        $this->followRedirects($invalid)
            ->assertSee('Tedarikçi Ürün Kaynağı Eşleştirme')
            ->assertSee('Hazır ürün kaynağı')
            ->assertSee('Etkin Promosyon')
            ->assertSee('data-supplier-mapping-visible="1"', false);

        $nonSupplierCompany = $this->createCompanyWithRoles('Sadece Müşteri Cari', ['customer']);

        $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $nonSupplierCompany->id . '/edit'))
            ->assertOk()
            ->assertSee('data-supplier-mapping-visible="0"', false);

        $supplierCompany = $this->createCompanyWithRoles('Tedarikçi Cari', ['supplier']);

        $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $supplierCompany->id . '/edit'))
            ->assertOk()
            ->assertSee('Tedarikçi Ürün Kaynağı Eşleştirme')
            ->assertSee('data-supplier-mapping-visible="1"', false)
            ->assertSee('Etkin Promosyon');
    }

    public function test_company_store_persists_supplier_mapping_and_show_renders_safe_summary(): void
    {
        [$supplier] = $this->createAccessibleSupplierFixture('İlpen', 'ILPEN');

        $response = $this->actingAs($this->owner, 'web')
            ->post($this->tenantUrl('/admin/companies'), [
                'identity_type' => 'company',
                'legal_name' => 'İlpen Ticari Cari',
                'tax_number' => '1234567890',
                'status' => 'active',
                'roles' => ['customer', 'supplier'],
                'supplier_id' => $supplier->id,
            ]);

        $company = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'İlpen Ticari Cari')
            ->firstOrFail();

        $response->assertRedirect($this->tenantUrl('/admin/companies/' . $company->id));

        $companyLink = CurrentAccountLink::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('link_id', $company->id)
            ->firstOrFail();

        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $companyLink->current_account_id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);

        $this->assertDatabaseHas('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $companyLink->current_account_id,
            'link_type' => CurrentAccountLink::LINK_TENANT_SUPPLIER_ACCESS,
        ]);

        $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '?tab=tedarikci'))
            ->assertOk()
            ->assertSee('Tedarikçi Eşleşme')
            ->assertSee('İlpen')
            ->assertSee('Tedarik ekranında kullanılabilir')
            ->assertDontSee('group_code')
            ->assertDontSee('raw_mapping')
            ->assertDontSee('password')
            ->assertDontSee('smtp_password');
    }

    public function test_supplier_role_without_mapping_shows_warning_and_non_supplier_payload_does_not_link_source(): void
    {
        [$supplier] = $this->createAccessibleSupplierFixture('Akdeniz', 'AKDENIZ');

        $supplierCompany = $this->createCompanyWithRoles('Kaynaksız Tedarikçi Cari', ['supplier']);

        $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $supplierCompany->id . '?tab=tedarikci'))
            ->assertOk()
            ->assertSee('Bu cari tedarikçi olarak işaretli fakat hazır ürün kaynağı eşleşmemiş.');

        $response = $this->actingAs($this->owner, 'web')
            ->post($this->tenantUrl('/admin/companies'), [
                'identity_type' => 'company',
                'legal_name' => 'Müşteri Kaynak Yok',
                'status' => 'active',
                'roles' => ['customer'],
                'supplier_id' => $supplier->id,
            ]);

        $company = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'Müşteri Kaynak Yok')
            ->firstOrFail();

        $response->assertRedirect($this->tenantUrl('/admin/companies/' . $company->id));

        $companyLink = CurrentAccountLink::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('link_id', $company->id)
            ->firstOrFail();

        $this->assertDatabaseMissing('current_account_links', [
            'tenant_account_id' => $this->tenant->id,
            'current_account_id' => $companyLink->current_account_id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplier->id,
        ]);
    }

    public function test_linked_current_account_redirects_to_company_and_procurement_surfaces_company_match_without_tenant_leak(): void
    {
        [$supplier, $source] = $this->createAccessibleSupplierFixture('Yeni Nesil', 'YENI');
        $company = $this->createCompanyWithRoles('Yeni Nesil Cari', ['supplier'], $supplier->id);
        $procurement = $this->createProcurementRecord($supplier, $source, 'SP-MAP-001');

        $companyLink = CurrentAccountLink::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('link_id', $company->id)
            ->firstOrFail();

        $linkedAccount = CurrentAccount::query()->findOrFail($companyLink->current_account_id);

        $foreignCompany = $this->createForeignTenantSupplierCompany($supplier->id);

        $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/current-accounts/' . $linkedAccount->id))
            ->assertRedirect($this->tenantUrl('/admin/companies/' . $company->id));

        $index = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/procurements'));

        $index->assertOk()
            ->assertSee('Talep Hazırlanacak Tedarikçiler')
            ->assertSee($supplier->name)
            ->assertDontSee($foreignCompany->legal_name)
            ->assertDontSee('Bakiye')
            ->assertDontSee('Ödeme Detayı');
        $show = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/procurements/' . $procurement->id));

        $show->assertOk()
            ->assertSee('Tedarik Detayı')
            ->assertSee('Üst Sıradaki İş')
            ->assertDontSee($foreignCompany->legal_name)
            ->assertDontSee('group_code')
            ->assertDontSee('raw_mapping');
    }

    public function test_procurement_warns_without_breaking_when_supplier_has_no_company_mapping(): void
    {
        [$supplier, $source] = $this->createAccessibleSupplierFixture('Eşleşmesiz Kaynak', 'ESLESME');
        $procurement = $this->createProcurementRecord($supplier, $source, 'SP-MAP-002');

        $index = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/procurements'));

        $index->assertOk()
            ->assertSee('Talep Hazırlanacak Tedarikçiler')
            ->assertSee($supplier->name);

        $show = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/procurements/' . $procurement->id . '?tab=tedarikci'));

        $show->assertOk()
            ->assertSee('Tedarik Detayı')
            ->assertSee('Üst Sıradaki İş')
            ->assertSee($supplier->name);
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

        if ($supplier->name !== $name || $supplier->status !== 'active') {
            $supplier->forceFill([
                'name' => $name,
                'status' => 'active',
            ])->save();
        }

        $source = SupplierSource::query()->create([
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

        return [$supplier, $source];
    }

    private function createCompanyWithRoles(string $name, array $roles, ?int $supplierId = null): Company
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
            ->firstOrFail();
    }

    private function createForeignTenantSupplierCompany(int $supplierId): Company
    {
        TenantSupplierAccess::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->foreignTenant->id,
                'supplier_id' => $supplierId,
            ],
            [
                'is_active' => true,
                'can_view_products' => true,
                'can_request_purchase' => true,
            ]
        );

        $company = Company::query()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'legal_name' => 'Foreign Supplier Company',
            'status' => 'active',
        ]);

        $company->companyRoles()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'role_key' => 'supplier',
        ]);

        $linkedAccount = app(CurrentAccountSyncService::class)->ensureForCompany($company->fresh('companyRoles'));

        CurrentAccountLink::query()->create([
            'tenant_account_id' => $this->foreignTenant->id,
            'current_account_id' => $linkedAccount->id,
            'link_type' => CurrentAccountLink::LINK_SUPPLIER,
            'link_id' => $supplierId,
            'is_primary' => true,
            'meta_json' => ['linked_via' => 'foreign_test'],
        ]);

        return $company;
    }

    private function createProcurementRecord(Supplier $supplier, SupplierSource $source, string $orderNumber): OrderItemProcurement
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $orderNumber,
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
            'product_source' => 'supplier_feed',
            'product_name' => $supplier->code . ' Ürün',
            'product_code' => $supplier->code . '-001',
            'supplier_id' => null,
            'supplier_source_id' => $source->id,
            'quantity' => 100,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => $supplier->code . ' Ürün',
                'product_code' => $supplier->code . '-001',
                'supplier_name' => $supplier->name,
                'warning_badges' => [],
                'group_code' => 'HIDDEN-GROUP',
                'raw_mapping' => ['secret' => 'hidden'],
            ],
            'price_snapshot' => [
                'unit_price' => 20,
                'line_total' => 2000,
                'vat_total' => 400,
            ],
            'stock_snapshot' => [
                'local_stock_quantity' => 0,
                'supplier_stock_quantity' => 40,
                'safe_stock_quantity' => 0,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 24,
            'discount_rate' => 5,
            'unit_price' => 20,
            'line_total' => 2000,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        app(WorkFormCreationService::class)->createForOrder($order, $this->owner);

        return $item->fresh(['procurement.workForm', 'procurement.order', 'procurement.order.customer'])->procurement;
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
