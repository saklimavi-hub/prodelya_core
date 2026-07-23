<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\OrderItemPrintProduction;
use App\Models\Role;
use App\Models\StandardPrintType;
use App\Models\TenantAccount;
use App\Models\TenantPrintSetting;
use App\Models\User;
use App\Services\ProductionCreationService;
use App\Services\TenantPrintSettingSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaklimaviPrintDefaultsAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;
    private Company $customer;
    private Company $subcontractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();
        $this->subcontractor = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'M13 B1 Fason Ltd.',
            'short_name' => 'M13 B1 Fason',
            'status' => 'active',
        ]);
        $this->subcontractor->companyRoles()->create([
            'tenant_account_id' => $this->tenant->id,
            'role_key' => 'print_fason',
        ]);

        app(TenantPrintSettingSyncService::class)->syncForTenant($this->tenant);
    }

    public function test_saklimavi_print_defaults_are_aligned_through_tenant_scoped_update_route(): void
    {
        $expectedModes = [
            'UV_PRINT' => StandardPrintType::MODE_INTERNAL,
            'LASER_PRINT' => StandardPrintType::MODE_INTERNAL,
            'PAD_PRINT' => StandardPrintType::MODE_OUTSOURCED,
            'HOT_STAMPING' => StandardPrintType::MODE_OUTSOURCED,
        ];

        $this->settingByCode('UV_PRINT')->forceFill(['production_mode' => StandardPrintType::MODE_BOTH])->save();
        $this->settingByCode('PAD_PRINT')->forceFill(['production_mode' => StandardPrintType::MODE_BOTH])->save();

        foreach ($expectedModes as $code => $mode) {
            $setting = $this->settingByCode($code);
            $originalSubcontractor = $setting->default_subcontractor_company_id;

            $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->put(route('admin.settings.print-settings.update', $setting), $this->settingPayload($setting, [
                    'production_mode' => $mode,
                ]))
                ->assertRedirect(route('admin.settings.print-settings.edit', $setting));

            $setting = $setting->fresh();
            $this->assertSame($mode, $setting->production_mode, $code . ' production mode mismatch');
            $this->assertSame($originalSubcontractor, $setting->default_subcontractor_company_id);
        }
    }

    public function test_saklimavi_alignment_does_not_rewrite_historical_productions(): void
    {
        $internal = $this->createProductionJob('SP-M13B1-HIST-IN', OrderItemPrintProduction::TYPE_INTERNAL);
        $outsourced = $this->createProductionJob('SP-M13B1-HIST-OUT', OrderItemPrintProduction::TYPE_OUTSOURCED);

        $before = OrderItemPrintProduction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->pluck('production_type', 'id')
            ->all();

        foreach ([
            'UV_PRINT' => StandardPrintType::MODE_INTERNAL,
            'PAD_PRINT' => StandardPrintType::MODE_OUTSOURCED,
        ] as $code => $mode) {
            $setting = $this->settingByCode($code);

            $this->actingAs($this->adminUser)
                ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
                ->put(route('admin.settings.print-settings.update', $setting), $this->settingPayload($setting, [
                    'production_mode' => $mode,
                ]))
                ->assertRedirect(route('admin.settings.print-settings.edit', $setting));
        }

        $after = OrderItemPrintProduction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->pluck('production_type', 'id')
            ->all();

        $this->assertSame($before, $after);
        $this->assertSame(OrderItemPrintProduction::TYPE_INTERNAL, $internal->fresh()->production_type);
        $this->assertSame(OrderItemPrintProduction::TYPE_OUTSOURCED, $outsourced->fresh()->production_type);
    }

    public function test_saklimavi_exact_override_still_wins_over_tenant_default(): void
    {
        $uv = $this->settingByCode('UV_PRINT');
        $uv->forceFill([
            'production_mode' => StandardPrintType::MODE_OUTSOURCED,
            'default_subcontractor_company_id' => $this->subcontractor->id,
        ])->save();

        $pad = $this->settingByCode('PAD_PRINT');
        $pad->forceFill([
            'production_mode' => StandardPrintType::MODE_INTERNAL,
            'default_subcontractor_company_id' => null,
        ])->save();

        $order = $this->createOrder('SP-M13B1-OVERRIDE');
        $item = $this->createOrderItem($order);
        $explicitInternal = $this->createPrint($order, $item, $uv, [
            'print_type' => 'UV Baskı',
            'production_type' => 'İç üretim',
        ]);
        $explicitOutsourced = $this->createPrint($order, $item, $pad, [
            'print_type' => 'Tampon Baskı',
            'production_type' => 'Dış üretim / Fason',
            'subcontractor_company_id' => $this->subcontractor->id,
        ]);

        $service = app(ProductionCreationService::class);
        $internalProduction = $service->createForOrderItemPrint($explicitInternal, null, $this->adminUser);
        $outsourcedProduction = $service->createForOrderItemPrint($explicitOutsourced, null, $this->adminUser);

        $this->assertSame(OrderItemPrintProduction::TYPE_INTERNAL, $internalProduction->production_type);
        $this->assertNull($internalProduction->production_company_id);
        $this->assertSame(OrderItemPrintProduction::TYPE_OUTSOURCED, $outsourcedProduction->production_type);
        $this->assertSame($this->subcontractor->id, $outsourcedProduction->production_company_id);
    }

    private function settingByCode(string $code): TenantPrintSetting
    {
        return TenantPrintSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->whereHas('standardPrintType', fn ($query) => $query->where('code', $code))
            ->firstOrFail();
    }

    private function settingPayload(TenantPrintSetting $setting, array $overrides = []): array
    {
        return array_merge([
            'custom_name' => $setting->custom_name,
            'is_active' => $setting->is_active ? '1' : '0',
            'production_mode' => $setting->production_mode,
            'requires_graphic' => $setting->requires_graphic ? '1' : '0',
            'requires_production' => $setting->requires_production ? '1' : '0',
            'requires_setup' => $setting->requires_setup ? '1' : '0',
            'setup_types' => $setting->setup_types ?: [],
            'default_subcontractor_company_id' => $setting->default_subcontractor_company_id,
            'default_subcontractor_current_account_id' => $setting->default_subcontractor_current_account_id,
            'default_currency' => $setting->default_currency,
            'default_unit_price' => $setting->default_unit_price,
            'default_setup_cost' => $setting->default_setup_cost,
            'notes' => $setting->notes,
        ], $overrides);
    }

    private function createProductionJob(string $documentNumber, string $productionType): OrderItemPrintProduction
    {
        $order = $this->createOrder($documentNumber);
        $item = $this->createOrderItem($order);
        $print = OrderItemPrint::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'print_type' => 'M13-B1 Tarihsel Baskı',
            'print_quantity' => 10,
            'production_type' => $productionType,
            'status' => 'pending',
        ]);

        return OrderItemPrintProduction::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'order_item_print_id' => $print->id,
            'production_type' => $productionType,
            'production_status' => OrderItemPrintProduction::STATUS_PENDING,
            'planned_quantity' => 10,
            'completed_quantity' => 0,
            'remaining_quantity' => 10,
            'qc_status' => OrderItemPrintProduction::QC_WAITING,
        ]);
    }

    private function createOrder(string $documentNumber): Order
    {
        return Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => $documentNumber,
            'customer_company_id' => $this->customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);
    }

    private function createOrderItem(Order $order): OrderItem
    {
        return OrderItem::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_name' => 'M13-B1 Test Ürünü',
            'product_code' => 'M13-B1-ITEM',
            'quantity' => 10,
            'unit' => 'Adet',
            'has_print' => true,
            'status' => 'pending',
        ]);
    }

    private function createPrint(Order $order, OrderItem $item, TenantPrintSetting $setting, array $overrides): OrderItemPrint
    {
        return OrderItemPrint::query()->create(array_merge([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'tenant_print_setting_id' => $setting->id,
            'standard_print_type_id' => $setting->standard_print_type_id,
            'print_type' => $setting->displayName(),
            'print_option' => 'Tek taraf',
            'print_quantity' => 10,
            'print_unit_price' => 1,
            'print_total' => 10,
            'status' => 'pending',
        ], $overrides));
    }
}
