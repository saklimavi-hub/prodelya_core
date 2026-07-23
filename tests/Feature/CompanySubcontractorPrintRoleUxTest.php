<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\UserRole;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySubcontractorPrintRoleUxTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $owner;
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
            'name' => 'Fason Role Owner',
            'email' => 'fason-role-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'role_id' => $tenantOwnerRole->id,
        ]);

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'current_accounts',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );
    }

    public function test_company_create_and_edit_show_fason_role_labels(): void
    {
        $create = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/create'));

        $create->assertOk()
            ->assertSee('Fason Baskı Firması')
            ->assertSee('Fason Üretim Firması')
            ->assertSee('Nakliye / Kargo');

        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Role Label Test Company',
            'status' => 'active',
        ]);

        $edit = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id . '/edit'));

        $edit->assertOk()
            ->assertSee('Fason Baskı Firması')
            ->assertSee('Fason Üretim Firması')
            ->assertSee('Nakliye / Kargo');
    }

    public function test_company_show_displays_fason_role_summary(): void
    {
        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Fason Ozet Test',
            'status' => 'active',
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $company->id,
            'role_key' => 'print_fason',
        ]);

        $response = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/companies/' . $company->id));

        $response->assertOk()
            ->assertSee('Üretim ve Fason Bilgisi')
            ->assertSee('Fason Baskı Firması')
            ->assertSee('Bu cari üretim ve baskı akışlarında seçilebilir.');
    }

    public function test_production_assignment_lists_only_active_tenant_fason_companies(): void
    {
        $allowedPrintCompany = $this->createRoleCompany('Allowed Print Fason', 'print_fason', 'active');
        $allowedProductionCompany = $this->createRoleCompany('Allowed Production Fason', 'production_partner', 'active');
        $this->createRoleCompany('Passive Fason Partner', 'print_fason', 'blocked');

        $plainCompany = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Plain Tenant Company',
            'status' => 'active',
        ]);

        $foreignTenant = TenantAccount::query()->create([
            'name' => 'Foreign Fason Tenant',
            'legal_name' => 'Foreign Fason Tenant Ltd.',
            'slug' => 'foreign-fason-tenant',
            'panel_subdomain' => 'foreign-fason-tenant',
            'status' => 'active',
        ]);

        $foreignCompany = Company::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'legal_name' => 'Foreign Fason Partner',
            'status' => 'active',
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'company_id' => $foreignCompany->id,
            'role_key' => 'print_fason',
        ]);

        $production = $this->createProduction('SP-FASON-UX-001');

        $show = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/productions/' . $production->id . '/subcontract-assignment'));

        $show->assertOk()
            ->assertSee('Fason firma seçimi')
            ->assertSee('Seçilen Firmaya Ata')
            ->assertSee($allowedPrintCompany->legal_name)
            ->assertSee($allowedProductionCompany->legal_name)
            ->assertDontSee($plainCompany->legal_name)
            ->assertDontSee('Passive Fason Partner')
            ->assertDontSee($foreignCompany->legal_name);
    }

    public function test_internal_assignment_allows_empty_company_and_external_assignment_keeps_company_id(): void
    {
        $partner = $this->createRoleCompany('Fason Assignment Partner', 'print_fason', 'active');
        $production = $this->createProduction('SP-FASON-UX-002');

        $this->actingAs($this->owner, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $production->id . '/assignment'), [
                'production_type' => OrderItemPrintProduction::TYPE_INTERNAL,
                'production_company_id' => '',
                'production_unit_name' => 'İç Hat 01',
                'assigned_to' => '',
                'cliche_required' => '0',
                'production_note' => 'İç üretime alındı',
                'return_to' => 'show',
            ])
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $production->id));

        $production = $production->fresh();
        $this->assertSame(OrderItemPrintProduction::TYPE_INTERNAL, $production->production_type);
        $this->assertNull($production->production_company_id);

        $this->actingAs($this->owner, 'web')
            ->patch($this->tenantUrl('/admin/productions/' . $production->id . '/assignment'), [
                'production_type' => OrderItemPrintProduction::TYPE_OUTSOURCED,
                'production_company_id' => $partner->id,
                'production_unit_name' => '',
                'assigned_to' => '',
                'cliche_required' => '0',
                'production_note' => 'Fason firmaya yönlendirildi',
                'return_to' => 'show',
            ])
            ->assertRedirect($this->tenantUrl('/admin/productions/' . $production->id));

        $this->assertSame($partner->id, $production->fresh()->production_company_id);
    }

    public function test_quote_create_does_not_surface_forced_fason_company_selector(): void
    {
        $response = $this->actingAs($this->owner, 'web')
            ->get($this->tenantUrl('/admin/promotion-quotes/create'));

        $response->assertOk()
            ->assertDontSee('Fason Firma')
            ->assertDontSee('Fason Baskı Firması');
    }

    private function createRoleCompany(string $legalName, string $roleKey, string $status): Company
    {
        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => $legalName,
            'status' => $status,
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $company->id,
            'role_key' => $roleKey,
        ]);

        return $company;
    }

    private function createProduction(string $orderNumber): OrderItemPrintProduction
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
            'product_source' => 'manual',
            'product_name' => 'Fason UX Product',
            'product_code' => 'FASON-UX-001',
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

        app(WorkFormCreationService::class)->createForOrder($order, $this->owner);

        $production = $print->fresh(['production'])->production;
        $production->forceFill([
            'production_type' => OrderItemPrintProduction::TYPE_OUTSOURCED,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'production_company_id' => null,
            'sent_to_subcontractor_at' => null,
        ])->save();

        return $production->fresh([
            'productionCompany',
            'orderItemPrint.subcontractorCompany',
            'order.customer',
            'workForm',
        ]);
    }

    private function tenantUrl(string $path): string
    {
        return 'http://' . $this->tenant->panel_subdomain . '.' . self::CENTRAL_HOST . $path;
    }
}
