<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\TenantAccount;
use App\Models\TenantPrintOption;
use App\Models\TenantPrintSetting;
use App\Models\User;
use App\Services\TenantPrintOptionService;
use App\Services\TenantPrintSettingSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuotePrintOptionIntegrationTest extends TestCase
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

        config()->set('prodelya.features.promotion_intermediate_element_enabled', true);

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        app(TenantPrintSettingSyncService::class)->syncForTenant($this->tenant);
    }

    public function test_create_screen_contains_print_option_binding_and_store_persists_option_with_setup_defaults(): void
    {
        $setting = TenantPrintSetting::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->whereHas('standardPrintType', fn ($query) => $query->where('code', 'HOT_STAMPING'))
            ->firstOrFail();

        app(TenantPrintOptionService::class)->ensureDefaultsForSetting($setting);
        $option = TenantPrintOption::query()
            ->where('tenant_print_setting_id', $setting->id)
            ->where('code', 'hot-stamping-new-cliche')
            ->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'))
            ->assertOk()
            ->assertSee('tenant_print_option_id', false)
            ->assertSee('Yeni klişe üretilecek');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), [
                'customer_company_id' => $this->customer->id,
                'quote_date' => '2026-07-02',
                'valid_until' => '2026-07-09',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'show_print_price_details_to_customer' => '1',
                'items' => [[
                    'product_name' => 'Print Option Ürünü',
                    'product_code' => 'PO-001',
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'list_price' => '5',
                    'discount_rate' => '0',
                    'unit_price' => '5',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '1',
                    'prints' => [[
                        'tenant_print_setting_id' => $setting->id,
                        'standard_print_type_id' => $setting->standard_print_type_id,
                        'tenant_print_option_id' => $option->id,
                        'print_type' => $setting->displayName(),
                        'print_option' => $option->name,
                        'cliche_status' => 'Yeni üretilecek',
                        'print_quantity' => '100',
                        'print_unit_price' => '10',
                        'base_print_unit_price' => '10',
                    ]],
                ]],
            ]);

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();
        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));

        $print = $quote->items()->with('prints')->firstOrFail()->prints->firstOrFail();
        $this->assertSame($option->id, $print->tenant_print_option_id);
        $this->assertSame($option->name, $print->print_option);
        $this->assertSame('cliche', $print->setup_type);
        $this->assertSame('Yeni üretilecek', $print->setup_status);
    }
}
