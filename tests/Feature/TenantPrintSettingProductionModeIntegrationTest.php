<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CurrentAccountTransaction;
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
use App\Services\SubcontractorProductionCurrentAccountSyncService;
use App\Services\TenantPrintSettingSyncService;
use App\Services\WorkFormCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantPrintSettingProductionModeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private User $productionUser;
    private TenantAccount $tenant;
    private Company $customer;
    private Company $subcontractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->productionUser = $this->createUserWithRole('production', 'tenant-print-mode-production');
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        $this->subcontractor = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Tenant Mode Fason Ltd.',
            'short_name' => 'TM Fason',
            'status' => 'active',
        ]);
        $this->subcontractor->companyRoles()->create([
            'tenant_account_id' => $this->tenant->id,
            'role_key' => 'print_fason',
        ]);

        app(TenantPrintSettingSyncService::class)->syncForTenant($this->tenant);
    }

    public function test_service_applies_tenant_production_modes_and_respects_explicit_choices(): void
    {
        $internalSetting = $this->settingByCode('LASER_PRINT');
        $internalSetting->forceFill([
            'production_mode' => StandardPrintType::MODE_INTERNAL,
            'default_subcontractor_company_id' => $this->subcontractor->id,
        ])->save();

        $outsourcedSetting = $this->settingByCode('UV_PRINT');
        $outsourcedSetting->forceFill([
            'production_mode' => StandardPrintType::MODE_OUTSOURCED,
            'default_subcontractor_company_id' => $this->subcontractor->id,
        ])->save();

        $bothSetting = $this->settingByCode('HOT_STAMPING');
        $bothSetting->forceFill([
            'production_mode' => StandardPrintType::MODE_BOTH,
            'default_subcontractor_company_id' => $this->subcontractor->id,
        ])->save();

        $order = $this->createOrder('SP-TPM-001');
        $item = $this->createOrderItem($order, ['has_print' => true, 'quantity' => 100]);

        $internalPrint = $this->createPrint($order, $item, [
            'tenant_print_setting_id' => $internalSetting->id,
            'standard_print_type_id' => $internalSetting->standard_print_type_id,
            'print_type' => 'Lazer Baskı',
        ]);
        $outsourcedPrint = $this->createPrint($order, $item, [
            'tenant_print_setting_id' => $outsourcedSetting->id,
            'standard_print_type_id' => $outsourcedSetting->standard_print_type_id,
            'print_type' => 'UV Baskı',
        ]);
        $bothDefaultPrint = $this->createPrint($order, $item, [
            'tenant_print_setting_id' => $bothSetting->id,
            'standard_print_type_id' => $bothSetting->standard_print_type_id,
            'print_type' => 'Sıcak Baskı',
        ]);
        $bothExplicitSubcontractorPrint = $this->createPrint($order, $item, [
            'tenant_print_setting_id' => $bothSetting->id,
            'standard_print_type_id' => $bothSetting->standard_print_type_id,
            'print_type' => 'Sıcak Baskı',
            'subcontractor_company_id' => $this->subcontractor->id,
        ]);
        $explicitInternalPrint = $this->createPrint($order, $item, [
            'tenant_print_setting_id' => $outsourcedSetting->id,
            'standard_print_type_id' => $outsourcedSetting->standard_print_type_id,
            'print_type' => 'UV Baskı',
            'production_type' => 'İç üretim',
        ]);

        $service = app(ProductionCreationService::class);

        $internalProduction = $service->createForOrderItemPrint($internalPrint, null, $this->adminUser);
        $outsourcedProduction = $service->createForOrderItemPrint($outsourcedPrint, null, $this->adminUser);
        $bothDefaultProduction = $service->createForOrderItemPrint($bothDefaultPrint, null, $this->adminUser);
        $bothExplicitSubcontractorProduction = $service->createForOrderItemPrint($bothExplicitSubcontractorPrint, null, $this->adminUser);
        $explicitInternalProduction = $service->createForOrderItemPrint($explicitInternalPrint, null, $this->adminUser);

        $this->assertSame(OrderItemPrintProduction::TYPE_INTERNAL, $internalProduction->production_type);
        $this->assertNull($internalProduction->production_company_id);

        $this->assertSame(OrderItemPrintProduction::TYPE_OUTSOURCED, $outsourcedProduction->production_type);
        $this->assertSame($this->subcontractor->id, $outsourcedProduction->production_company_id);

        $this->assertSame(OrderItemPrintProduction::TYPE_INTERNAL, $bothDefaultProduction->production_type);
        $this->assertNull($bothDefaultProduction->production_company_id);

        $this->assertSame(OrderItemPrintProduction::TYPE_OUTSOURCED, $bothExplicitSubcontractorProduction->production_type);
        $this->assertSame($this->subcontractor->id, $bothExplicitSubcontractorProduction->production_company_id);

        $this->assertSame(OrderItemPrintProduction::TYPE_INTERNAL, $explicitInternalProduction->production_type);
        $this->assertNull($explicitInternalProduction->production_company_id);
    }

    public function test_foreign_default_company_is_ignored_and_legacy_creation_behavior_stays_safe(): void
    {
        $foreignTenant = TenantAccount::query()->create([
            'name' => 'Foreign Print Mode Tenant',
            'slug' => 'foreign-print-mode-tenant',
            'status' => 'active',
        ]);

        $foreignCompany = Company::query()->create([
            'tenant_account_id' => $foreignTenant->id,
            'legal_name' => 'Foreign Fason Ltd.',
            'status' => 'active',
        ]);

        $outsourcedSetting = $this->settingByCode('UV_PRINT');
        $outsourcedSetting->forceFill([
            'production_mode' => StandardPrintType::MODE_OUTSOURCED,
            'default_subcontractor_company_id' => $foreignCompany->id,
        ])->save();

        $order = $this->createOrder('SP-TPM-002');
        $item = $this->createOrderItem($order, ['has_print' => true]);

        $foreignDefaultPrint = $this->createPrint($order, $item, [
            'tenant_print_setting_id' => $outsourcedSetting->id,
            'standard_print_type_id' => $outsourcedSetting->standard_print_type_id,
            'print_type' => 'UV Baskı',
        ]);

        $legacyPrint = $this->createPrint($order, $item, [
            'tenant_print_setting_id' => null,
            'standard_print_type_id' => null,
            'print_type' => 'Eski Serbest Baskı',
            'production_type' => 'Dış üretim / Fason',
            'subcontractor_company_id' => $this->subcontractor->id,
        ]);

        $service = app(ProductionCreationService::class);
        $foreignDefaultProduction = $service->createForOrderItemPrint($foreignDefaultPrint, null, $this->adminUser);
        $legacyProduction = $service->createForOrderItemPrint($legacyPrint, null, $this->adminUser);

        $this->assertSame(OrderItemPrintProduction::TYPE_OUTSOURCED, $foreignDefaultProduction->production_type);
        $this->assertNull($foreignDefaultProduction->production_company_id);

        $this->assertSame(OrderItemPrintProduction::TYPE_OUTSOURCED, $legacyProduction->production_type);
        $this->assertSame($this->subcontractor->id, $legacyProduction->production_company_id);
    }

    public function test_quote_to_order_conversion_creates_productions_from_tenant_print_settings_without_breaking_no_print_flow(): void
    {
        $outsourcedSetting = $this->settingByCode('UV_PRINT');
        $outsourcedSetting->forceFill([
            'custom_name' => 'Q2O UV Fason',
            'production_mode' => StandardPrintType::MODE_OUTSOURCED,
            'default_subcontractor_company_id' => $this->subcontractor->id,
        ])->save();

        $internalSetting = $this->settingByCode('LASER_PRINT');
        $internalSetting->forceFill([
            'custom_name' => 'Q2O Lazer İç',
            'production_mode' => StandardPrintType::MODE_INTERNAL,
        ])->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), $this->baseQuotePayload([
                'items' => [
                    [
                        'product_name' => 'Baskılı Ürün',
                        'product_code' => 'TPM-Q2O-PRINT',
                        'quantity' => '100',
                        'unit' => 'Adet',
                        'list_price' => '10',
                        'discount_rate' => '0',
                        'unit_price' => '10',
                        'manual_unit_price' => '1',
                        'vat_rate' => '20',
                        'has_print' => '1',
                        'prints' => [
                            [
                                'tenant_print_setting_id' => $outsourcedSetting->id,
                                'standard_print_type_id' => $outsourcedSetting->standard_print_type_id,
                                'print_type' => 'UV Baskı',
                                'print_option' => 'Tek taraf',
                                'print_quantity' => '100',
                                'print_unit_price' => '2',
                            ],
                            [
                                'tenant_print_setting_id' => $internalSetting->id,
                                'standard_print_type_id' => $internalSetting->standard_print_type_id,
                                'print_type' => 'Lazer Baskı',
                                'print_option' => 'İsim',
                                'print_quantity' => '100',
                                'print_unit_price' => '1',
                            ],
                        ],
                    ],
                    [
                        'product_name' => 'Baskısız Ürün',
                        'product_code' => 'TPM-Q2O-NOPRINT',
                        'quantity' => '20',
                        'unit' => 'Adet',
                        'list_price' => '4',
                        'discount_rate' => '0',
                        'unit_price' => '4',
                        'manual_unit_price' => '1',
                        'vat_rate' => '20',
                        'has_print' => '0',
                        'prints' => [],
                    ],
                ],
            ]))
            ->assertRedirect();

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $order->load('items.prints.production');
        $printedItem = $order->items->firstWhere('has_print', true);
        $plainItem = $order->items->firstWhere('has_print', false);

        $this->assertNotNull($printedItem);
        $this->assertNotNull($plainItem);
        $this->assertCount(2, $printedItem->prints);
        $this->assertCount(0, $plainItem->prints);
        $this->assertCount(2, OrderItemPrintProduction::query()->where('order_id', $order->id)->get());

        $outsourcedProduction = $printedItem->prints
            ->firstWhere('tenant_print_setting_id', $outsourcedSetting->id)
            ?->production;
        $internalProduction = $printedItem->prints
            ->firstWhere('tenant_print_setting_id', $internalSetting->id)
            ?->production;

        $this->assertNotNull($outsourcedProduction);
        $this->assertNotNull($internalProduction);
        $this->assertSame(OrderItemPrintProduction::TYPE_OUTSOURCED, $outsourcedProduction->production_type);
        $this->assertSame($this->subcontractor->id, $outsourcedProduction->production_company_id);
        $this->assertSame(OrderItemPrintProduction::TYPE_INTERNAL, $internalProduction->production_type);
        $this->assertNull($internalProduction->production_company_id);
        $this->assertArrayNotHasKey('default_unit_price', $outsourcedProduction->production_snapshot);
        $this->assertArrayNotHasKey('default_setup_cost', $outsourcedProduction->production_snapshot);
    }

    public function test_default_subcontractor_company_remains_compatible_with_cost_sync_and_safe_visibility(): void
    {
        $outsourcedSetting = $this->settingByCode('UV_PRINT');
        $outsourcedSetting->forceFill([
            'production_mode' => StandardPrintType::MODE_OUTSOURCED,
            'default_subcontractor_company_id' => $this->subcontractor->id,
        ])->save();

        $order = $this->createOrder('SP-TPM-003');
        $item = $this->createOrderItem($order, ['has_print' => true]);
        $print = $this->createPrint($order, $item, [
            'tenant_print_setting_id' => $outsourcedSetting->id,
            'standard_print_type_id' => $outsourcedSetting->standard_print_type_id,
            'print_type' => 'UV Baskı',
        ]);

        $workForm = app(WorkFormCreationService::class)->createForOrder($order, $this->adminUser)->first();
        $this->assertNotNull($workForm);
        $production = $print->fresh()->production;
        $this->assertNotNull($production);

        $production->forceFill([
            'subcontractor_cost' => 2500,
            'subcontractor_cost_currency' => 'TRY',
            'updated_by' => $this->adminUser->id,
        ])->save();

        $transaction = app(SubcontractorProductionCurrentAccountSyncService::class)
            ->syncProduction($production->fresh(['order.customer', 'orderItem', 'orderItemPrint', 'productionCompany.companyRoles']));

        $this->assertNotNull($transaction);
        $this->assertSame(CurrentAccountTransaction::TYPE_SUBCONTRACTOR_DEBIT, $transaction->transaction_type);
        $this->assertSame(CurrentAccountTransaction::DIRECTION_DEBIT, $transaction->direction);
        $this->assertSame('2500.00', $transaction->amount);

        $productionShowForOperator = $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.productions.show', $production->fresh()));

        $productionShowForOperator->assertOk();
        $productionShowForOperator->assertDontSee('2500', false);
        $productionShowForOperator->assertDontSee('Cari Hareket', false);

        $publicTracking = $this->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('public.work-forms.track', $production->workForm->public_tracking_token));

        $publicTracking->assertOk();
        $publicTracking->assertDontSee('2500', false);
        $publicTracking->assertDontSee('Cari Hareket', false);
        $publicTracking->assertDontSee('physical_path');
    }

    private function baseQuotePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_company_id' => $this->customer->id,
            'quote_date' => '2026-06-17',
            'valid_until' => '2026-06-24',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'delivery_type' => 'Kargo',
            'notes' => 'Tenant print setting production mode integration test',
            'items' => [],
        ], $overrides);
    }

    private function settingByCode(string $code): TenantPrintSetting
    {
        return TenantPrintSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->whereHas('standardPrintType', fn ($query) => $query->where('code', $code))
            ->firstOrFail();
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

    private function createOrderItem(Order $order, array $overrides = []): OrderItem
    {
        return OrderItem::query()->create(array_merge([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Tenant Print Setting Test Ürünü',
            'product_code' => 'TPM-BASE-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'description' => 'Tenant print setting integration test item',
            'product_snapshot' => [
                'product_name' => 'Tenant Print Setting Test Ürünü',
                'product_code' => 'TPM-BASE-001',
                'warning_badges' => [],
            ],
            'stock_snapshot' => [
                'local_stock_quantity' => 0,
                'supplier_stock_quantity' => 0,
                'safe_stock_quantity' => 0,
            ],
            'catalog_source' => 'tenant_catalog',
            'list_price' => 120,
            'discount_rate' => 10,
            'unit_price' => 99.9,
            'line_total' => 999,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ], $overrides));
    }

    private function createPrint(Order $order, OrderItem $item, array $overrides = []): OrderItemPrint
    {
        return OrderItemPrint::query()->create(array_merge([
            'tenant_account_id' => $order->tenant_account_id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'tenant_print_setting_id' => null,
            'standard_print_type_id' => null,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf',
            'print_location' => 'Gövde',
            'production_type' => null,
            'subcontractor_company_id' => null,
            'print_color' => 'Tek Renk',
            'print_size' => 'Standart',
            'cliche_status' => null,
            'print_quantity' => $item->quantity,
            'print_unit_price' => 5,
            'print_total' => 50,
            'note' => 'Test baskı',
            'production_note' => null,
            'status' => 'draft',
        ], $overrides));
    }

    private function createUserWithRole(string $roleKey, string $emailPrefix): User
    {
        $user = User::query()->create([
            'name' => ucfirst($roleKey) . ' User',
            'email' => $emailPrefix . '@prodelya.local',
            'password' => bcrypt('password'),
        ]);

        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        $user->userRoles()->create([
            'tenant_account_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }
}
