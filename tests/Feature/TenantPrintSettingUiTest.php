<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\StandardPrintType;
use App\Models\TenantAccount;
use App\Models\TenantPrintSetting;
use App\Models\User;
use App\Services\TenantPrintSettingSyncService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantPrintSettingUiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private User $financeUser;
    private User $productionUser;
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
        $this->financeUser = $this->createUserWithRole('finance', 'finance-print-settings-ui');
        $this->productionUser = $this->createUserWithRole('production', 'production-print-settings-ui');

        app(TenantPrintSettingSyncService::class)->syncForTenant($this->tenant);
    }

    public function test_index_menu_filters_and_tenant_scope_work(): void
    {
        $uv = StandardPrintType::query()->where('code', 'UV_PRINT')->firstOrFail();
        $laser = StandardPrintType::query()->where('code', 'LASER_PRINT')->firstOrFail();

        $uvSetting = TenantPrintSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('standard_print_type_id', $uv->id)
            ->firstOrFail();
        $uvSetting->forceFill([
            'custom_name' => 'Tenant UV Ozel',
            'production_mode' => StandardPrintType::MODE_OUTSOURCED,
            'requires_setup' => true,
            'setup_types' => ['cliche'],
        ])->save();

        $laserSetting = TenantPrintSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('standard_print_type_id', $laser->id)
            ->firstOrFail();
        $laserSetting->forceFill([
            'is_active' => false,
            'production_mode' => StandardPrintType::MODE_INTERNAL,
        ])->save();

        $otherTenant = $this->createOtherTenant();
        app(TenantPrintSettingSyncService::class)->syncForTenant($otherTenant);
        TenantPrintSetting::query()
            ->where('tenant_account_id', $otherTenant->id)
            ->firstOrFail()
            ->forceFill(['custom_name' => 'Yabanci Tenant Baski'])
            ->save();

        $dashboard = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.dashboard'));

        $dashboard->assertOk();
        $dashboard->assertSee('Baskı Ayarları');
        $dashboard->assertSee(route('admin.settings.print-settings.index'), false);

        $index = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.print-settings.index'));

        $index->assertOk();
        $index->assertSee('Baskı Ayarları');
        $index->assertSee('Tenant UV Ozel');
        $index->assertSee('Lazer Baskı');
        $index->assertDontSee('Yabanci Tenant Baski');
        $index->assertSee($this->tenant->name);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.print-settings.index', ['production_mode' => StandardPrintType::MODE_OUTSOURCED]))
            ->assertOk()
            ->assertSee('Tenant UV Ozel')
            ->assertDontSee('Lazer Baskı');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.print-settings.index', ['status' => 'passive']))
            ->assertOk()
            ->assertSee('Lazer Baskı')
            ->assertDontSee('Tenant UV Ozel');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.print-settings.index', ['requires_setup' => '1']))
            ->assertOk()
            ->assertSee('Tenant UV Ozel')
            ->assertDontSee('Lazer Baskı');
    }

    public function test_edit_screen_updates_operational_fields_and_authorized_financial_defaults(): void
    {
        $setting = TenantPrintSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->whereHas('standardPrintType', fn ($query) => $query->where('code', 'UV_PRINT'))
            ->firstOrFail();

        $foreignTenant = $this->createOtherTenant();
        app(TenantPrintSettingSyncService::class)->syncForTenant($foreignTenant);
        $foreignSetting = TenantPrintSetting::query()->where('tenant_account_id', $foreignTenant->id)->firstOrFail();

        $subcontractor = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Fason UI Test Ltd.',
            'short_name' => 'Fason UI',
            'status' => 'active',
        ]);
        $subcontractor->companyRoles()->create([
            'tenant_account_id' => $this->tenant->id,
            'role_key' => 'print_fason',
        ]);

        $foreignCompany = Company::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'legal_name' => 'Foreign Fason Ltd.',
            'short_name' => 'Foreign Fason',
            'status' => 'active',
        ]);
        $foreignCompany->companyRoles()->create([
            'tenant_account_id' => $foreignTenant->id,
            'role_key' => 'print_fason',
        ]);

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.print-settings.index'))
            ->assertOk()
            ->assertSee('Finansal Varsayılanlar');

        $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.print-settings.index'))
            ->assertOk()
            ->assertDontSee('Finansal Varsayılanlar')
            ->assertDontSee('123,45');

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.print-settings.edit', $setting))
            ->assertOk()
            ->assertSee('Varsayılan Birim Baskı Fiyatı')
            ->assertSee('Varsayılan Setup / Hazırlık Maliyeti');

        $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.print-settings.edit', $setting))
            ->assertOk()
            ->assertDontSee('Varsayılan Birim Baskı Fiyatı')
            ->assertDontSee('Varsayılan Setup / Hazırlık Maliyeti')
            ->assertDontSee('name="default_unit_price"', false)
            ->assertDontSee('name="default_setup_cost"', false);

        $response = $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.settings.print-settings.update', $setting), [
                'custom_name' => 'UV Tenant Ayari',
                'is_active' => '1',
                'production_mode' => StandardPrintType::MODE_OUTSOURCED,
                'requires_graphic' => '0',
                'requires_production' => '1',
                'requires_setup' => '1',
                'setup_types' => ['cliche', 'film'],
                'default_subcontractor_company_id' => $subcontractor->id,
                'default_currency' => 'USD',
                'default_unit_price' => '123.45',
                'default_setup_cost' => '67.89',
                'notes' => 'TENANT-PRINT-SETTING-NOTU',
            ]);

        $response->assertRedirect(route('admin.settings.print-settings.edit', $setting));

        $setting = $setting->fresh();
        $this->assertSame('UV Tenant Ayari', $setting->custom_name);
        $this->assertSame(StandardPrintType::MODE_OUTSOURCED, $setting->production_mode);
        $this->assertFalse($setting->requires_graphic);
        $this->assertTrue($setting->requires_production);
        $this->assertTrue($setting->requires_setup);
        $this->assertSame(['cliche', 'film'], $setting->setup_types);
        $this->assertSame($subcontractor->id, $setting->default_subcontractor_company_id);
        $this->assertSame('USD', $setting->default_currency);
        $this->assertSame('123.45', (string) $setting->default_unit_price);
        $this->assertSame('67.89', (string) $setting->default_setup_cost);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.print-settings.edit', $foreignSetting))
            ->assertForbidden();

        $this->actingAs($this->financeUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.settings.print-settings.edit', $setting))
            ->put(route('admin.settings.print-settings.update', $setting), [
                'custom_name' => 'Gecersiz Fason Secimi',
                'is_active' => '1',
                'production_mode' => StandardPrintType::MODE_BOTH,
                'requires_graphic' => '1',
                'requires_production' => '1',
                'requires_setup' => '0',
                'default_subcontractor_company_id' => $foreignCompany->id,
            ])
            ->assertSessionHasErrors('default_subcontractor_company_id');
    }

    public function test_unauthorized_user_cannot_change_financial_defaults_and_sync_keeps_overrides(): void
    {
        $setting = TenantPrintSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->whereHas('standardPrintType', fn ($query) => $query->where('code', 'HOT_STAMPING'))
            ->firstOrFail();

        $setting->forceFill([
            'default_currency' => 'TRY',
            'default_unit_price' => 111.11,
            'default_setup_cost' => 222.22,
            'custom_name' => 'Sicak Tenant Ozel',
        ])->save();

        $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.settings.print-settings.update', $setting), [
                'custom_name' => 'Operasyon Guncellemesi',
                'is_active' => '1',
                'production_mode' => StandardPrintType::MODE_INTERNAL,
                'requires_graphic' => '1',
                'requires_production' => '1',
                'requires_setup' => '1',
                'setup_types' => ['other'],
                'default_currency' => 'EUR',
                'default_unit_price' => '999.99',
                'default_setup_cost' => '888.88',
            ])
            ->assertRedirect(route('admin.settings.print-settings.edit', $setting));

        $setting = $setting->fresh();
        $this->assertSame('Operasyon Guncellemesi', $setting->custom_name);
        $this->assertSame('TRY', $setting->default_currency);
        $this->assertSame('111.11', (string) $setting->default_unit_price);
        $this->assertSame('222.22', (string) $setting->default_setup_cost);

        $deletedSetting = TenantPrintSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->whereHas('standardPrintType', fn ($query) => $query->where('code', 'FOIL'))
            ->firstOrFail();
        $deletedSetting->delete();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.settings.print-settings.sync'))
            ->assertRedirect(route('admin.settings.print-settings.index'));

        $this->assertDatabaseHas('tenant_print_settings', [
            'tenant_account_id' => $this->tenant->id,
            'standard_print_type_id' => StandardPrintType::query()->where('code', 'FOIL')->value('id'),
        ]);

        $preserved = TenantPrintSetting::query()->findOrFail($setting->id);
        $this->assertSame('Operasyon Guncellemesi', $preserved->custom_name);
        $this->assertSame('111.11', (string) $preserved->default_unit_price);

        $workForm = $this->createPublicTrackingWorkForm();
        $public = $this->get(route('public.work-forms.track', $workForm->public_tracking_token));
        $public->assertOk();
        $public->assertDontSee('111,11');
        $public->assertDontSee('222,22');
        $public->assertDontSee('TENANT-PRINT-SETTING-NOTU');
        $public->assertDontSee('group_code', false);
        $public->assertDontSee('file_path', false);
        $public->assertDontSee('physical_path', false);

        $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index'))
            ->assertOk()
            ->assertDontSee('111,11')
            ->assertDontSee('222,22');

        $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index'))
            ->assertOk()
            ->assertDontSee('111,11')
            ->assertDontSee('222,22');
    }

    private function createUserWithRole(string $roleKey, string $emailPrefix): User
    {
        $user = User::query()->create([
            'name' => ucfirst($roleKey) . ' Print Setting User',
            'email' => $emailPrefix . '@prodelya.local',
            'password' => bcrypt('password'),
        ]);

        $role = Role::query()->where('key', $roleKey)->firstOrFail();
        $user->userRoles()->create([
            'role_id' => $role->id,
            'tenant_account_id' => $this->tenant->id,
        ]);

        return $user;
    }

    private function createOtherTenant(): TenantAccount
    {
        return TenantAccount::query()->create([
            'name' => 'Other Tenant Print UI',
            'legal_name' => 'Other Tenant Print UI Ltd.',
            'slug' => 'other-tenant-print-ui',
            'panel_subdomain' => 'other-tenant-print-ui',
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
            'document_number' => 'SP-PRINT-UI-' . fake()->unique()->numerify('####'),
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
            'product_name' => 'Tenant Print Setting Public Product',
            'product_code' => 'PRINT-UI-001',
            'quantity' => 3,
            'unit' => 'Adet',
            'catalog_source' => 'tenant_catalog',
            'has_print' => false,
            'status' => 'pending',
        ]);

        return app(WorkFormCreationService::class)
            ->createForOrder($order, $this->adminUser)
            ->first()
            ->fresh();
    }
}
