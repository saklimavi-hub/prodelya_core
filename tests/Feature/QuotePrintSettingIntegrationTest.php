<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemPrintProduction;
use App\Models\Role;
use App\Models\StandardPrintType;
use App\Models\TenantAccount;
use App\Models\TenantPrintSetting;
use App\Models\User;
use App\Services\TenantPrintSettingSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotePrintSettingIntegrationTest extends TestCase
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
        $this->productionUser = $this->createUserWithRole('production', 'quote-print-setting-production');
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        $this->subcontractor = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Quote Print Setting Fason Ltd.',
            'short_name' => 'QPS Fason',
            'status' => 'active',
        ]);
        $this->subcontractor->companyRoles()->create([
            'tenant_account_id' => $this->tenant->id,
            'role_key' => 'print_fason',
        ]);

        app(TenantPrintSettingSyncService::class)->syncForTenant($this->tenant);
    }

    public function test_create_form_uses_tenant_active_print_settings_and_hides_financial_defaults_from_unauthorized_users(): void
    {
        $uvSetting = $this->settingByCode('UV_PRINT');
        $uvSetting->forceFill([
            'custom_name' => 'QUOTE-SETTING-AKTIF-UV',
            'default_unit_price' => 1234.56,
            'default_setup_cost' => 4321.78,
        ])->save();

        $inactive = $this->settingByCode('LASER_PRINT');
        $inactive->forceFill([
            'custom_name' => 'QUOTE-SETTING-PASIF-LAZER',
            'is_active' => false,
        ])->save();

        $otherTenant = $this->createOtherTenant();
        app(TenantPrintSettingSyncService::class)->syncForTenant($otherTenant);
        $foreignSetting = TenantPrintSetting::query()
            ->where('tenant_account_id', $otherTenant->id)
            ->firstOrFail();
        $foreignSetting->forceFill([
            'custom_name' => 'QUOTE-SETTING-YABANCI-TENANT',
        ])->save();

        $adminResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $adminResponse->assertOk();
        $adminResponse->assertSee('QUOTE-SETTING-AKTIF-UV');
        $adminResponse->assertDontSee('QUOTE-SETTING-PASIF-LAZER');
        $adminResponse->assertDontSee('QUOTE-SETTING-YABANCI-TENANT');
        $adminResponse->assertSee('1234.56', false);
        $adminResponse->assertSee('4321.78', false);

        $productionResponse = $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $productionResponse->assertOk();
        $productionResponse->assertSee('QUOTE-SETTING-AKTIF-UV');
        $productionResponse->assertDontSee('1234.56', false);
        $productionResponse->assertDontSee('4321.78', false);
    }

    public function test_quote_store_saves_print_setting_links_and_backward_compatible_display_name(): void
    {
        $uvSetting = $this->settingByCode('UV_PRINT');
        $laserType = StandardPrintType::query()->where('code', 'LASER_PRINT')->firstOrFail();

        $uvSetting->forceFill([
            'custom_name' => 'Quote UV Tenant Ayari',
            'production_mode' => StandardPrintType::MODE_OUTSOURCED,
            'default_subcontractor_company_id' => $this->subcontractor->id,
            'default_unit_price' => 9.99,
        ])->save();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), $this->baseQuotePayload([
                'items' => [[
                    'product_name' => 'Tenant Print Setting Ürünü',
                    'product_code' => 'TPS-001',
                    'quantity' => '50',
                    'unit' => 'Adet',
                    'list_price' => '10',
                    'discount_rate' => '0',
                    'unit_price' => '10',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '1',
                    'prints' => [[
                        'tenant_print_setting_id' => $uvSetting->id,
                        'standard_print_type_id' => $laserType->id,
                        'print_type' => 'Eski UV Stringi',
                        'print_option' => 'Tek taraf baskılı',
                        'print_quantity' => '50',
                        'print_unit_price' => '9.99',
                    ]],
                ]],
            ]));

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();
        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));

        $print = $quote->items()->with('prints')->firstOrFail()->prints->firstOrFail();
        $this->assertSame($uvSetting->id, $print->tenant_print_setting_id);
        $this->assertSame($uvSetting->standard_print_type_id, $print->standard_print_type_id);
        $this->assertSame('Quote UV Tenant Ayari', $print->print_type);
        $this->assertSame('Dış üretim / Fason', $print->production_type);
        $this->assertSame($this->subcontractor->id, $print->subcontractor_company_id);
    }

    public function test_foreign_or_inactive_print_settings_are_rejected_on_new_quote_save(): void
    {
        $inactive = $this->settingByCode('HOT_STAMPING');
        $inactive->forceFill(['is_active' => false])->save();

        $otherTenant = $this->createOtherTenant();
        app(TenantPrintSettingSyncService::class)->syncForTenant($otherTenant);
        $foreignSetting = TenantPrintSetting::query()
            ->where('tenant_account_id', $otherTenant->id)
            ->firstOrFail();

        $foreignResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.promotion-quotes.create'))
            ->post(route('admin.promotion-quotes.store'), $this->baseQuotePayload([
                'items' => [[
                    'product_name' => 'Foreign Setting Test',
                    'product_code' => 'FOREIGN-001',
                    'quantity' => '10',
                    'unit' => 'Adet',
                    'has_print' => '1',
                    'prints' => [[
                        'tenant_print_setting_id' => $foreignSetting->id,
                        'print_type' => 'Foreign',
                        'print_quantity' => '10',
                        'print_unit_price' => '1',
                    ]],
                ]],
            ]));

        $foreignResponse->assertSessionHasErrors();
        $this->assertSame(0, Order::query()->where('document_type', 'quote')->count());

        $inactiveResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.promotion-quotes.create'))
            ->post(route('admin.promotion-quotes.store'), $this->baseQuotePayload([
                'items' => [[
                    'product_name' => 'Inactive Setting Test',
                    'product_code' => 'INACTIVE-001',
                    'quantity' => '10',
                    'unit' => 'Adet',
                    'has_print' => '1',
                    'prints' => [[
                        'tenant_print_setting_id' => $inactive->id,
                        'print_type' => 'Inactive',
                        'print_quantity' => '10',
                        'print_unit_price' => '1',
                    ]],
                ]],
            ]));

        $inactiveResponse->assertSessionHasErrors();
        $this->assertSame(0, Order::query()->where('document_type', 'quote')->count());
    }

    public function test_edit_preserves_existing_inactive_setting_and_conversion_copies_print_setting_fields(): void
    {
        $uvSetting = $this->settingByCode('UV_PRINT');
        $uvSetting->forceFill([
            'custom_name' => 'Quote Edit Inactive UV',
            'production_mode' => StandardPrintType::MODE_INTERNAL,
        ])->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), $this->baseQuotePayload([
                'items' => [
                    [
                        'product_name' => 'Baskılı Ürün',
                        'product_code' => 'PRINT-ITEM-001',
                        'quantity' => '100',
                        'unit' => 'Adet',
                        'list_price' => '8',
                        'discount_rate' => '0',
                        'unit_price' => '8',
                        'manual_unit_price' => '1',
                        'vat_rate' => '20',
                        'has_print' => '1',
                        'prints' => [[
                            'tenant_print_setting_id' => $uvSetting->id,
                            'standard_print_type_id' => $uvSetting->standard_print_type_id,
                            'print_type' => 'Legacy UV',
                            'print_option' => 'Tek taraf baskılı',
                            'print_quantity' => '100',
                            'print_unit_price' => '2',
                        ]],
                    ],
                    [
                        'product_name' => 'Baskısız Ürün',
                        'product_code' => 'NOPRINT-001',
                        'quantity' => '40',
                        'unit' => 'Adet',
                        'list_price' => '5',
                        'discount_rate' => '0',
                        'unit_price' => '5',
                        'manual_unit_price' => '1',
                        'vat_rate' => '20',
                        'has_print' => '0',
                        'prints' => [],
                    ],
                ],
            ]))
            ->assertRedirect();

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();
        $uvSetting->forceFill(['is_active' => false])->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.edit', $quote))
            ->assertOk()
            ->assertSee('Quote Edit Inactive UV');

        $legacyQuote = $this->createLegacyQuoteWithoutPrintSetting();
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.edit', $legacyQuote))
            ->assertOk()
            ->assertSee('Eski Serbest Baskı');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $order->load('items.prints');
        $printedItem = $order->items->firstWhere('has_print', true);
        $plainItem = $order->items->firstWhere('has_print', false);

        $this->assertNotNull($printedItem);
        $this->assertNotNull($plainItem);
        $this->assertCount(1, $printedItem->prints);
        $this->assertCount(0, $plainItem->prints);
        $this->assertSame($uvSetting->id, $printedItem->prints->first()->tenant_print_setting_id);
        $this->assertSame($uvSetting->standard_print_type_id, $printedItem->prints->first()->standard_print_type_id);
        $this->assertSame(1, OrderItemPrintGraphic::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, OrderItemPrintProduction::query()->where('order_id', $order->id)->count());
    }

    private function baseQuotePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_company_id' => $this->customer->id,
            'quote_date' => '2026-06-16',
            'valid_until' => '2026-06-23',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'delivery_type' => 'Kargo',
            'notes' => 'Quote print setting integration test',
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

    private function createUserWithRole(string $roleKey, string $emailPrefix): User
    {
        $user = User::query()->create([
            'name' => ucfirst($roleKey) . ' Quote Print Setting User',
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
            'name' => 'Quote Print Setting Other Tenant',
            'legal_name' => 'Quote Print Setting Other Tenant Ltd.',
            'slug' => 'quote-print-setting-other-tenant',
            'panel_subdomain' => 'quote-print-setting-other-tenant',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);
    }

    private function createLegacyQuoteWithoutPrintSetting(): Order
    {
        $quote = Order::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TKF-LEGACY-' . fake()->unique()->numerify('####'),
            'customer_company_id' => $this->customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'invoice_status' => 'fis',
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        $item = $quote->items()->create([
            'tenant_account_id' => $this->tenant->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Legacy Product',
            'product_code' => 'LEGACY-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'has_print' => true,
            'status' => 'pending',
        ]);

        $item->prints()->create([
            'tenant_account_id' => $this->tenant->id,
            'order_id' => $quote->id,
            'print_type' => 'Eski Serbest Baskı',
            'print_option' => 'Legacy seçenek',
            'print_quantity' => 10,
            'print_unit_price' => 0,
            'print_total' => 0,
            'status' => 'draft',
        ]);

        return $quote->fresh('items.prints');
    }
}
