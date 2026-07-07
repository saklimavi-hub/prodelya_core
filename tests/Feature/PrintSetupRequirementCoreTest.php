<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemPrintSetupRequirement;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantPrintSetting;
use App\Models\User;
use App\Services\OrderItemPrintSetupRequirementService;
use App\Services\TenantPrintSettingSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintSetupRequirementCoreTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
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
        $this->productionUser = $this->createUserWithRole('production', 'setup-requirement-production');

        app(TenantPrintSettingSyncService::class)->syncForTenant($this->tenant);
    }

    public function test_service_creates_setup_requirements_only_for_required_print_settings_and_prevents_duplicates(): void
    {
        $uvSetting = $this->settingByCode('UV_PRINT');
        $uvSetting->forceFill([
            'requires_setup' => true,
            'setup_types' => ['cliche', 'film'],
            'default_setup_cost' => 450.00,
        ])->save();

        $laserSetting = $this->settingByCode('LASER_PRINT');
        $laserSetting->forceFill([
            'requires_setup' => false,
            'setup_types' => [],
        ])->save();

        $order = $this->makeOrder();
        $item = $order->items()->create([
            'tenant_account_id' => $this->tenant->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Setup Test Product',
            'product_code' => 'SETUP-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'has_print' => true,
            'status' => 'pending',
        ]);

        $printA = $item->prints()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'tenant_print_setting_id' => $uvSetting->id,
            'standard_print_type_id' => $uvSetting->standard_print_type_id,
            'print_type' => 'UV Tenant Setup',
            'print_option' => 'Ön yüz',
            'print_quantity' => 100,
            'status' => 'draft',
        ]);

        $printB = $item->prints()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'tenant_print_setting_id' => $laserSetting->id,
            'standard_print_type_id' => $laserSetting->standard_print_type_id,
            'print_type' => 'Lazer Setup Yok',
            'print_option' => 'Arka yüz',
            'print_quantity' => 100,
            'status' => 'draft',
        ]);

        $legacyPrint = $item->prints()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'print_type' => 'Legacy Print',
            'print_option' => 'Legacy',
            'print_quantity' => 100,
            'status' => 'draft',
        ]);

        $service = app(OrderItemPrintSetupRequirementService::class);

        $reportA = $service->createForPrint($printA->fresh());
        $reportB = $service->createForPrint($printB->fresh());
        $reportLegacy = $service->createForPrint($legacyPrint->fresh());

        $this->assertSame(2, $reportA['created']);
        $this->assertSame(0, $reportB['created']);
        $this->assertSame(0, $reportLegacy['created']);
        $this->assertSame(2, OrderItemPrintSetupRequirement::query()->where('order_item_print_id', $printA->id)->count());
        $this->assertSame(0, OrderItemPrintSetupRequirement::query()->where('order_item_print_id', $printB->id)->count());
        $this->assertSame(0, OrderItemPrintSetupRequirement::query()->where('order_item_print_id', $legacyPrint->id)->count());

        $requirements = OrderItemPrintSetupRequirement::query()
            ->where('order_item_print_id', $printA->id)
            ->orderBy('setup_type')
            ->get();

        $this->assertSame(OrderItemPrintSetupRequirement::STATUS_PENDING, $requirements[0]->status);
        $this->assertNull($requirements[0]->cost);
        $this->assertSame(['cliche', 'film'], $requirements->pluck('setup_type')->all());

        $service->syncForPrint($printA->fresh());
        $this->assertSame(2, OrderItemPrintSetupRequirement::query()->where('order_item_print_id', $printA->id)->count());
    }

    public function test_quote_to_order_conversion_creates_setup_requirements_and_status_actions_update_them_with_tenant_scope(): void
    {
        $uvSetting = $this->settingByCode('UV_PRINT');
        $uvSetting->forceFill([
            'custom_name' => 'UV Setup Ayarı',
            'requires_setup' => true,
            'setup_types' => ['cliche'],
        ])->save();

        $laserSetting = $this->settingByCode('LASER_PRINT');
        $laserSetting->forceFill([
            'custom_name' => 'Lazer Setup Yok',
            'requires_setup' => false,
            'setup_types' => [],
        ])->save();

        $quote = $this->makeQuoteWithTwoPrints($uvSetting, $laserSetting);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->firstOrFail();
        $order->load('items.prints.setupRequirements', 'workForms', 'printProductions');

        $printedItem = $order->items->firstOrFail();
        $this->assertCount(2, $printedItem->prints);

        $setupPrint = $printedItem->prints->firstWhere('print_type', 'UV Setup Ayarı');
        $plainPrint = $printedItem->prints->firstWhere('print_type', 'Lazer Setup Yok');

        $this->assertNotNull($setupPrint);
        $this->assertNotNull($plainPrint);
        $this->assertCount(1, $setupPrint->setupRequirements);
        $this->assertCount(0, $plainPrint->setupRequirements);

        $requirement = $setupPrint->setupRequirements->first();
        $this->assertSame(OrderItemPrintSetupRequirement::STATUS_PENDING, $requirement->status);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.print-setup-requirements.requested', $requirement))
            ->assertRedirect();

        $requirement = $requirement->fresh();
        $this->assertSame(OrderItemPrintSetupRequirement::STATUS_REQUESTED, $requirement->status);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.print-setup-requirements.ready', $requirement))
            ->assertRedirect();

        $requirement = $requirement->fresh();
        $this->assertSame(OrderItemPrintSetupRequirement::STATUS_READY, $requirement->status);
        $this->assertNotNull($requirement->completed_at);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.print-setup-requirements.cancel', $requirement), [
                'reason' => 'Setup iptal testi',
            ])
            ->assertRedirect();

        $requirement = $requirement->fresh();
        $this->assertSame(OrderItemPrintSetupRequirement::STATUS_CANCELLED, $requirement->status);
        $this->assertSame('Setup iptal testi', $requirement->cancellation_reason);

        $foreignTenant = $this->createOtherTenant();
        app(TenantPrintSettingSyncService::class)->syncForTenant($foreignTenant);
        $foreignRequirement = OrderItemPrintSetupRequirement::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'order_id' => $order->id,
            'order_item_id' => $printedItem->id,
            'order_item_print_id' => $setupPrint->id,
            'setup_type' => OrderItemPrintSetupRequirement::TYPE_OTHER,
            'status' => OrderItemPrintSetupRequirement::STATUS_PENDING,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.print-setup-requirements.ready', $foreignRequirement))
            ->assertForbidden();
    }

    public function test_work_form_and_production_views_show_safe_setup_summary_without_cost_leakage(): void
    {
        $uvSetting = $this->settingByCode('UV_PRINT');
        $uvSetting->forceFill([
            'custom_name' => 'UV Setup Ekran Ayarı',
            'requires_setup' => true,
            'setup_types' => ['cliche'],
            'default_setup_cost' => 999.99,
            'notes' => 'SETUP-COST-SECRET',
        ])->save();

        $laserSetting = $this->settingByCode('LASER_PRINT');
        $laserSetting->forceFill([
            'custom_name' => 'Lazer Ekran Ayarı',
            'requires_setup' => false,
            'setup_types' => [],
        ])->save();

        $quote = $this->makeQuoteWithTwoPrints($uvSetting, $laserSetting);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->firstOrFail();

        /** @var OrderItemPrintProduction $setupProduction */
        $setupProduction = OrderItemPrintProduction::query()
            ->where('order_id', $order->id)
            ->whereHas('orderItemPrint', fn ($query) => $query->where('print_type', 'UV Setup Ekran Ayarı'))
            ->firstOrFail();
        $plainProduction = OrderItemPrintProduction::query()
            ->where('order_id', $order->id)
            ->whereHas('orderItemPrint', fn ($query) => $query->where('print_type', 'Lazer Ekran Ayarı'))
            ->firstOrFail();
        $requirement = OrderItemPrintSetupRequirement::query()
            ->where('order_item_print_id', $setupProduction->order_item_print_id)
            ->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.print-setup-requirements.ready', $requirement))
            ->assertRedirect();

        $workForm = $order->workForms()->firstOrFail()->fresh();
        $this->assertTrue((bool) data_get($workForm->production_snapshot, 'setup_required'));
        $this->assertSame('Klişe', data_get($workForm->production_snapshot, 'setup_summary.items.0.setup_type_label'));
        $this->assertStringNotContainsString('999.99', json_encode($workForm->production_snapshot));

        $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', [
                $setupProduction,
                'tab' => 'genel',
            ]))
            ->assertOk()
            ->assertSee('Hazırlık / Ara Eleman')
            ->assertSee('Klişe')
            ->assertSee('Hazır')
            ->assertDontSee('999.99')
            ->assertDontSee('TRY')
            ->assertDontSee('SETUP-COST-SECRET')
            ->assertDontSee('group_code', false)
            ->assertDontSee('file_path', false)
            ->assertDontSee('physical_path', false);

        $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', [
                $plainProduction,
                'tab' => 'genel',
            ]))
            ->assertOk()
            ->assertDontSee('Hazırlık / Ara Eleman')
            ->assertDontSee('Gerekli değil');

        $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm))
            ->assertOk()
            ->assertSee('3A. Hazırlık / Ara Eleman')
            ->assertSee('Klişe')
            ->assertSee('Hazır')
            ->assertDontSee('999.99')
            ->assertDontSee('SETUP-COST-SECRET');

        $this->get(route('public.work-forms.track', $workForm->public_tracking_token))
            ->assertOk()
            ->assertDontSee('999.99')
            ->assertDontSee('SETUP-COST-SECRET')
            ->assertDontSee('group_code', false)
            ->assertDontSee('file_path', false)
            ->assertDontSee('physical_path', false);
    }

    private function makeOrder(): Order
    {
        return Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'ORD-SETUP-' . fake()->unique()->numerify('####'),
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);
    }

    private function makeQuoteWithTwoPrints(TenantPrintSetting $setupSetting, TenantPrintSetting $plainSetting): Order
    {
        $quote = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'Q-SETUP-' . fake()->unique()->numerify('####'),
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Kargo',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $item = $quote->items()->create([
            'tenant_account_id' => $this->tenant->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Setup Quote Product',
            'product_code' => 'SETUP-QUOTE-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'line_total' => 1000,
            'has_print' => true,
            'status' => 'pending',
        ]);

        $item->prints()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'tenant_print_setting_id' => $setupSetting->id,
            'standard_print_type_id' => $setupSetting->standard_print_type_id,
            'print_type' => $setupSetting->displayName(),
            'print_option' => 'Ön yüz',
            'print_quantity' => 100,
            'status' => 'draft',
        ]);

        $item->prints()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'tenant_print_setting_id' => $plainSetting->id,
            'standard_print_type_id' => $plainSetting->standard_print_type_id,
            'print_type' => $plainSetting->displayName(),
            'print_option' => 'Arka yüz',
            'print_quantity' => 100,
            'status' => 'draft',
        ]);

        return $quote->fresh('items.prints');
    }

    private function settingByCode(string $code): TenantPrintSetting
    {
        return TenantPrintSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->whereHas('standardPrintType', fn ($query) => $query->where('code', $code))
            ->firstOrFail();
    }

    private function createUserWithRole(string $roleKey, string $emailPrefix): User
    {
        $user = User::query()->create([
            'name' => ucfirst($roleKey) . ' Setup User',
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
            'name' => 'Setup Other Tenant',
            'legal_name' => 'Setup Other Tenant Ltd.',
            'slug' => 'setup-other-tenant',
            'panel_subdomain' => 'setup-other-tenant',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }
}
