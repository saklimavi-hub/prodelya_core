<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StandardPrintType;
use App\Models\TenantAccount;
use App\Models\TenantPrintSetting;
use App\Models\User;
use App\Services\TenantPrintSettingSyncService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PrintSettingsCoreTest extends TestCase
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

    public function test_print_settings_core_tables_models_seed_and_sync_service_work_safely(): void
    {
        $this->assertTrue(Schema::hasTable('standard_print_types'));
        $this->assertTrue(Schema::hasTable('tenant_print_settings'));

        $uv = StandardPrintType::query()->where('code', 'UV_PRINT')->first();
        $laser = StandardPrintType::query()->where('code', 'LASER_PRINT')->first();
        $hotStamping = StandardPrintType::query()->where('code', 'HOT_STAMPING')->first();

        $this->assertNotNull($uv);
        $this->assertNotNull($laser);
        $this->assertNotNull($hotStamping);
        $this->assertSame('UV Baskı', $uv->safeName());
        $this->assertSame('HOT_STAMPING', $hotStamping->safeCode());
        $this->assertTrue($hotStamping->default_requires_setup);
        $this->assertSame(['cliche'], $hotStamping->default_setup_types);

        $setting = TenantPrintSetting::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'standard_print_type_id' => $uv->id,
            'custom_name' => null,
            'is_active' => true,
            'production_mode' => StandardPrintType::MODE_BOTH,
            'default_subcontractor_company_id' => null,
            'default_subcontractor_current_account_id' => null,
            'default_currency' => 'TRY',
            'default_unit_price' => 15.50,
            'default_setup_cost' => 25.00,
            'requires_graphic' => true,
            'requires_production' => true,
            'requires_setup' => false,
            'setup_types' => ['film', 'montage'],
        ]);

        $this->assertSame(['film', 'montage'], $setting->fresh()->setup_types);
        $this->assertSame('UV Baskı', $setting->displayName());
        $this->assertTrue($setting->effectiveRequiresGraphic());
        $this->assertTrue($setting->effectiveRequiresProduction());
        $this->assertFalse($setting->effectiveRequiresSetup());
        $this->assertTrue($setting->isInternalAllowed());
        $this->assertTrue($setting->isOutsourcedAllowed());

        $syncReport = app(TenantPrintSettingSyncService::class)->syncForTenant($this->tenant);
        $this->assertGreaterThan(0, $syncReport['created']);
        $this->assertSame(StandardPrintType::query()->where('status', StandardPrintType::STATUS_ACTIVE)->count(), TenantPrintSetting::query()->where('tenant_account_id', $this->tenant->id)->count());

        $syncAgain = app(TenantPrintSettingSyncService::class)->syncForTenant($this->tenant);
        $this->assertSame(0, $syncAgain['created']);
        $this->assertGreaterThan(0, $syncAgain['skipped_existing']);

        $existing = TenantPrintSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('standard_print_type_id', $hotStamping->id)
            ->firstOrFail();

        $existing->forceFill([
            'custom_name' => 'Tenant Sıcak Baskı',
            'requires_setup' => false,
            'setup_types' => ['other'],
            'default_unit_price' => 999.99,
        ])->save();

        app(TenantPrintSettingSyncService::class)->syncForTenant($this->tenant);

        $preserved = $existing->fresh();
        $this->assertSame('Tenant Sıcak Baskı', $preserved->custom_name);
        $this->assertFalse($preserved->requires_setup);
        $this->assertSame(['other'], $preserved->setup_types);
        $this->assertSame('999.99', (string) $preserved->default_unit_price);

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Print Tenant Two',
            'legal_name' => 'Print Tenant Two Ltd.',
            'slug' => 'print-tenant-two',
            'panel_subdomain' => 'print-tenant-two',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $otherSync = app(TenantPrintSettingSyncService::class)->syncForTenant($otherTenant);
        $this->assertGreaterThan(0, $otherSync['created']);

        $this->assertDatabaseMissing('tenant_print_settings', [
            'tenant_account_id' => $otherTenant->id,
            'custom_name' => 'Tenant Sıcak Baskı',
        ]);

        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Fason Test Firmasi',
            'short_name' => 'Fason Test',
            'status' => 'active',
        ]);

        $currentAccount = CurrentAccount::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'display_name' => 'Fason Cari',
            'legal_name' => 'Fason Cari Ltd.',
            'status' => CurrentAccount::STATUS_ACTIVE,
        ]);

        $laserSetting = TenantPrintSetting::query()->where('tenant_account_id', $this->tenant->id)->where('standard_print_type_id', $laser->id)->firstOrFail();
        $laserSetting->forceFill([
            'default_subcontractor_company_id' => $company->id,
            'default_subcontractor_current_account_id' => $currentAccount->id,
            'custom_name' => 'Lazer Ozel',
            'production_mode' => StandardPrintType::MODE_INTERNAL,
            'requires_graphic' => false,
            'requires_production' => true,
            'requires_setup' => true,
            'setup_types' => ['laser_template'],
        ])->save();

        $laserSetting = $laserSetting->fresh(['defaultSubcontractorCompany', 'defaultSubcontractorCurrentAccount', 'standardPrintType']);
        $this->assertSame('Lazer Ozel', $laserSetting->displayName());
        $this->assertFalse($laserSetting->effectiveRequiresGraphic());
        $this->assertTrue($laserSetting->effectiveRequiresProduction());
        $this->assertTrue($laserSetting->effectiveRequiresSetup());
        $this->assertSame(['laser_template'], $laserSetting->effectiveSetupTypes());
        $this->assertTrue($laserSetting->isInternalAllowed());
        $this->assertFalse($laserSetting->isOutsourcedAllowed());
        $this->assertSame($company->id, $laserSetting->defaultSubcontractorCompany?->id);
        $this->assertSame($currentAccount->id, $laserSetting->defaultSubcontractorCurrentAccount?->id);
    }

    public function test_sync_command_dry_run_and_public_surfaces_stay_clean(): void
    {
        TenantPrintSetting::query()->delete();

        $this->artisan('prodelya:sync-tenant-print-settings', [
            '--tenant' => $this->tenant->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, TenantPrintSetting::query()->where('tenant_account_id', $this->tenant->id)->count());

        $this->artisan('prodelya:sync-tenant-print-settings', [
            '--tenant' => $this->tenant->id,
        ])->assertExitCode(0);

        $this->assertGreaterThan(0, TenantPrintSetting::query()->where('tenant_account_id', $this->tenant->id)->count());

        $hotStamping = StandardPrintType::query()->where('code', 'HOT_STAMPING')->firstOrFail();
        $setting = TenantPrintSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('standard_print_type_id', $hotStamping->id)
            ->firstOrFail();

        $setting->forceFill([
            'default_unit_price' => 777.77,
            'default_setup_cost' => 888.88,
            'notes' => 'GIZLI-PRINT-SETTING',
        ])->save();

        $workForm = $this->createPublicTrackingWorkForm();

        $public = $this->get(route('public.work-forms.track', $workForm->public_tracking_token));
        $public->assertOk();
        $public->assertDontSee('777,77');
        $public->assertDontSee('888,88');
        $public->assertDontSee('GIZLI-PRINT-SETTING');
        $public->assertDontSee('group_code', false);
        $public->assertDontSee('file_path', false);
        $public->assertDontSee('physical_path', false);

        $adminShow = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm));

        $adminShow->assertOk();
        $adminShow->assertDontSee('777,77');
        $adminShow->assertDontSee('888,88');
        $adminShow->assertDontSee('GIZLI-PRINT-SETTING');
    }

    private function createPublicTrackingWorkForm()
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-PRINT-SET-' . fake()->unique()->numerify('####'),
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
            'product_name' => 'Print Setting Public Product',
            'product_code' => 'PRINT-SETTING-001',
            'quantity' => 5,
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
