<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\OrderItemPrintSetupRequirement;
use App\Models\OrderItemWorkForm;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantPrintSetting;
use App\Models\User;
use App\Services\DeliveryDataBuilder;
use App\Services\ProductionReadinessResolver;
use App\Services\TenantPrintSettingSyncService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantPrintSettingOperationCreationRulesTest extends TestCase
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
        $this->productionUser = $this->createUserWithRole('production', 'print-setting-op-rules');

        app(TenantPrintSettingSyncService::class)->syncForTenant($this->tenant);
    }

    public function test_requires_graphic_and_requires_production_control_operation_creation_and_legacy_fallback(): void
    {
        $noGraphic = $this->settingByCode('LASER_PRINT');
        $noGraphic->forceFill([
            'custom_name' => 'Grafiksiz Uretim',
            'requires_graphic' => false,
            'requires_production' => true,
            'requires_setup' => false,
            'setup_types' => [],
        ])->save();

        $noProduction = $this->settingByCode('CUTTING');
        $noProduction->forceFill([
            'custom_name' => 'Uretimsiz Grafik',
            'requires_graphic' => true,
            'requires_production' => false,
            'requires_setup' => true,
            'setup_types' => [OrderItemPrintSetupRequirement::TYPE_CLICHE],
        ])->save();

        $noOperations = $this->settingByCode('EMBOSSING');
        $noOperations->forceFill([
            'custom_name' => 'Operasyonsuz Baski',
            'requires_graphic' => false,
            'requires_production' => false,
            'requires_setup' => true,
            'setup_types' => [OrderItemPrintSetupRequirement::TYPE_FILM],
        ])->save();

        $noGraphicOrder = $this->convertQuoteWithPrintSetting($noGraphic, 'Grafiksiz Uretim Urunu');
        $noProductionOrder = $this->convertQuoteWithPrintSetting($noProduction, 'Uretimsiz Grafik Urunu');
        $noOperationsOrder = $this->convertQuoteWithPrintSetting($noOperations, 'Operasyonsuz Baski Urunu');
        $legacyOrder = $this->convertLegacyQuote('Legacy Tenant Setting Yok');
        $noPrintOrder = $this->convertNoPrintQuote('No Print Kural Urunu');

        $noGraphicWorkForm = $noGraphicOrder->workForms()->firstOrFail()->fresh();
        $noProductionWorkForm = $noProductionOrder->workForms()->firstOrFail()->fresh();
        $noOperationsWorkForm = $noOperationsOrder->workForms()->firstOrFail()->fresh();
        $legacyWorkForm = $legacyOrder->workForms()->firstOrFail()->fresh();
        $noPrintWorkForm = $noPrintOrder->workForms()->firstOrFail()->fresh();

        $this->assertSame(0, OrderItemPrintGraphic::query()->where('order_id', $noGraphicOrder->id)->count());
        $this->assertSame(1, OrderItemPrintProduction::query()->where('order_id', $noGraphicOrder->id)->count());
        $this->assertSame('gerekli_degil', data_get($noGraphicWorkForm->graphic_snapshot, 'status'));
        $this->assertSame('uretim_bekliyor', data_get($noGraphicWorkForm->production_snapshot, 'status'));

        $this->assertSame(1, OrderItemPrintGraphic::query()->where('order_id', $noProductionOrder->id)->count());
        $this->assertSame(0, OrderItemPrintProduction::query()->where('order_id', $noProductionOrder->id)->count());
        $this->assertSame('bekliyor', data_get($noProductionWorkForm->graphic_snapshot, 'status'));
        $this->assertSame('gerekli_degil', data_get($noProductionWorkForm->production_snapshot, 'status'));
        $this->assertSame(0, OrderItemPrintSetupRequirement::query()->where('order_id', $noProductionOrder->id)->count());

        $this->assertSame(0, OrderItemPrintGraphic::query()->where('order_id', $noOperationsOrder->id)->count());
        $this->assertSame(0, OrderItemPrintProduction::query()->where('order_id', $noOperationsOrder->id)->count());
        $this->assertSame('gerekli_degil', data_get($noOperationsWorkForm->graphic_snapshot, 'status'));
        $this->assertSame('gerekli_degil', data_get($noOperationsWorkForm->production_snapshot, 'status'));
        $this->assertSame(0, OrderItemPrintSetupRequirement::query()->where('order_id', $noOperationsOrder->id)->count());

        $this->assertSame(1, OrderItemPrintGraphic::query()->where('order_id', $legacyOrder->id)->count());
        $this->assertSame(1, OrderItemPrintProduction::query()->where('order_id', $legacyOrder->id)->count());
        $this->assertSame('bekliyor', data_get($legacyWorkForm->graphic_snapshot, 'status'));
        $this->assertSame('uretim_bekliyor', data_get($legacyWorkForm->production_snapshot, 'status'));

        $this->assertSame(0, OrderItemPrintGraphic::query()->where('order_id', $noPrintOrder->id)->count());
        $this->assertSame(0, OrderItemPrintProduction::query()->where('order_id', $noPrintOrder->id)->count());
        $this->assertSame('gerekli_degil', data_get($noPrintWorkForm->graphic_snapshot, 'status'));
        $this->assertSame('gerekli_degil', data_get($noPrintWorkForm->production_snapshot, 'status'));
    }

    public function test_requires_graphic_false_skips_graphic_waiting_and_allows_production_without_graphic_record(): void
    {
        $setting = $this->settingByCode('LASER_PRINT');
        $setting->forceFill([
            'custom_name' => 'Grafik Gerekmeyen Lazer',
            'requires_graphic' => false,
            'requires_production' => true,
            'requires_setup' => false,
            'setup_types' => [],
        ])->save();

        $order = $this->convertQuoteWithPrintSetting($setting, 'Grafiksiz Uretim Akis Urunu');
        $workForm = $order->workForms()->with(['procurement', 'delivery'])->firstOrFail();
        $production = OrderItemPrintProduction::query()
            ->where('order_id', $order->id)
            ->firstOrFail();

        $this->assertNull($production->graphicOperation()->first());

        $procurement = $workForm->procurement()->firstOrFail();
        $procurement->forceFill([
            'procurement_status' => 'tamami_geldi',
            'received_quantity' => $production->planned_quantity,
            'updated_by' => $this->adminUser->id,
        ])->save();

        $workForm->forceFill([
            'procurement_snapshot' => array_merge(
                is_array($workForm->procurement_snapshot) ? $workForm->procurement_snapshot : [],
                [
                    'procurement_status' => 'tamami_geldi',
                    'procurement_status_label' => 'Tamamı Geldi',
                    'public_status_label' => 'Ürün üretime hazır',
                    'received_quantity' => (float) $production->planned_quantity,
                ]
            ),
        ])->save();

        $readiness = app(ProductionReadinessResolver::class)->resolve($production->fresh([
            'graphicOperation.latestAttachment',
            'orderItemPrint.tenantPrintSetting.standardPrintType',
            'orderItemPrint.setupRequirements',
            'workForm.procurement',
            'workForm.attachments',
        ]));

        $this->assertFalse($readiness['graphic_required']);
        $this->assertTrue($readiness['graphic_ready']);
        $this->assertTrue($readiness['can_start']);
        $this->assertSame('Grafik Gerekli Değil', $readiness['graphic_status_label']);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->patch(route('admin.productions.update-status', $production), [
                'action' => 'assign_internal',
                'production_unit_name' => 'Lazer Hat 1',
            ])
            ->assertRedirect(route('admin.productions.show', $production));

        $ordersIndex = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index'));

        $ordersIndex->assertOk();
        $ordersIndex->assertSee($order->document_number);
        $ordersIndex->assertDontSee('Grafik Bekliyor');

        $graphicsIndex = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.graphics.index'));

        $graphicsIndex->assertOk();
        $graphicsIndex->assertDontSee($order->document_number);
        $graphicsIndex->assertDontSee('Grafiksiz Uretim Akis Urunu');
    }

    public function test_requires_production_false_skips_production_setup_and_delivery_waiting_while_showing_safe_labels(): void
    {
        $setting = $this->settingByCode('FOIL');
        $setting->forceFill([
            'custom_name' => 'Uretim Gerekmeyen Baski',
            'requires_graphic' => false,
            'requires_production' => false,
            'requires_setup' => true,
            'setup_types' => [OrderItemPrintSetupRequirement::TYPE_CLICHE],
        ])->save();

        $order = $this->convertQuoteWithPrintSetting($setting, 'Uretimsiz Teslimat Urunu');
        $workForm = $order->workForms()->with(['delivery'])->firstOrFail()->fresh();
        $delivery = $workForm->delivery()->firstOrFail();

        $this->assertSame(0, OrderItemPrintProduction::query()->where('order_id', $order->id)->count());
        $this->assertSame(0, OrderItemPrintSetupRequirement::query()->where('order_id', $order->id)->count());
        $this->assertSame('gerekli_degil', data_get($workForm->graphic_snapshot, 'status'));
        $this->assertSame('gerekli_degil', data_get($workForm->production_snapshot, 'status'));
        $this->assertSame('Grafik gerekli değil', data_get($workForm->graphic_snapshot, 'public_status_label'));
        $this->assertSame('Üretim gerekli değil', data_get($workForm->production_snapshot, 'public_status_label'));

        $deliverySnapshot = app(DeliveryDataBuilder::class)->build($workForm, $delivery);
        $this->assertNotContains('Üretim tamamlanmadan teslimat başlatılmamalı.', $deliverySnapshot['readiness_warnings']);

        $ordersIndex = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.orders.index'));

        $ordersIndex->assertOk();
        $ordersIndex->assertSee($order->document_number);
        $ordersIndex->assertDontSee('Üretim Bekliyor');
        $ordersIndex->assertDontSee('Grafik Bekliyor');

        $productionsIndex = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.index'));

        $productionsIndex->assertOk();
        $productionsIndex->assertDontSee($order->document_number);
        $productionsIndex->assertDontSee('Uretimsiz Teslimat Urunu');

        $publicTracking = $this->get(route('public.work-forms.track', $workForm->public_tracking_token));
        $publicTracking->assertOk();
        $publicTracking->assertSee('Grafik gerekli değil');
        $publicTracking->assertSee('Üretim gerekli değil');
        $publicTracking->assertDontSee('default_unit_price', false);
        $publicTracking->assertDontSee('default_setup_cost', false);
        $publicTracking->assertDontSee('group_code', false);
        $publicTracking->assertDontSee('file_path', false);
        $publicTracking->assertDontSee('physical_path', false);

        $productionShow = $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.work-forms.show', $workForm));

        $productionShow->assertOk();
        $productionShow->assertSee('Grafik Gerekli Değil');
        $productionShow->assertSee('Üretim Gerekli Değil');
        $productionShow->assertDontSee('default_unit_price', false);
        $productionShow->assertDontSee('default_setup_cost', false);
    }

    private function convertQuoteWithPrintSetting(TenantPrintSetting $setting, string $productName): Order
    {
        $order = $this->createOrderRecord($productName, true, [[
            'tenant_print_setting_id' => $setting->id,
            'standard_print_type_id' => $setting->standard_print_type_id,
            'print_type' => $setting->displayName(),
            'print_option' => 'Tek taraf',
            'print_quantity' => 100,
            'print_unit_price' => 3,
            'print_total' => 300,
            'status' => 'draft',
        ]], 'Tenant print setting operation creation test');

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        return $order->fresh(['workForms', 'items.prints', 'printProductions']);
    }

    private function convertLegacyQuote(string $productName): Order
    {
        $order = $this->createOrderRecord($productName, true, [[
            'print_type' => 'Legacy UV Baskı',
            'print_option' => 'Tek taraf',
            'production_type' => 'İç üretim',
            'print_quantity' => 100,
            'print_unit_price' => 3,
            'print_total' => 300,
            'status' => 'draft',
        ]], 'Legacy print fallback test');

        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        return $order->fresh(['workForms', 'items.prints', 'printProductions']);
    }

    private function convertNoPrintQuote(string $productName): Order
    {
        $order = $this->createOrderRecord($productName, false, [], 'No print compatibility test');
        app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser);

        return $order->fresh(['workForms', 'items.prints', 'printProductions']);
    }

    private function createOrderRecord(string $productName, bool $hasPrint, array $prints, string $notes): Order
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'customer_company_id' => $this->customer->id,
            'document_type' => 'order',
            'document_number' => 'SP-OPS-' . fake()->unique()->numerify('####'),
            'quote_date' => '2026-06-17',
            'valid_until' => '2026-06-24',
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 2000,
            'vat_total' => 400,
            'grand_total' => 2400,
            'product_total' => 2000,
            'print_total' => $hasPrint ? 300 : 0,
            'delivery_type' => 'Kargo',
            'notes' => $notes,
            'created_by' => $this->adminUser->id,
        ]);

        $item = $order->items()->create([
            'tenant_account_id' => $this->tenant->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => $productName,
            'product_code' => strtoupper(str_replace(' ', '-', $productName)),
            'quantity' => 100,
            'unit' => 'Adet',
            'list_price' => 20,
            'discount_rate' => 0,
            'unit_price' => 20,
            'line_total' => 2000,
            'has_print' => $hasPrint,
            'print_total' => $hasPrint ? 300 : 0,
            'status' => 'pending',
            'product_snapshot' => ['warning_badges' => []],
            'price_snapshot' => [
                'vat_mode' => 'taxable',
                'vat_rate' => 20,
                'product_total' => 2000,
                'print_total' => $hasPrint ? 300 : 0,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 400, 'scope' => 'general'],
                ],
            ],
            'stock_snapshot' => ['supplier_stock_quantity' => 500],
        ]);

        foreach ($prints as $printData) {
            $item->prints()->create(array_merge([
                'tenant_account_id' => $this->tenant->id,
                'order_id' => $order->id,
            ], $printData));
        }

        return $order->fresh('items.prints');
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
            'name' => ucfirst($roleKey) . ' Print Setting Rule User',
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
}
