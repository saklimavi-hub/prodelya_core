<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrintSetupRequirement;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantPrintSetting;
use App\Models\User;
use App\Services\TenantPrintSettingSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotePrintDefaultPriceSuggestionTest extends TestCase
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
        $this->productionUser = $this->createUserWithRole('production', 'quote-default-price-production');
        $this->customer = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        app(TenantPrintSettingSyncService::class)->syncForTenant($this->tenant);
    }

    public function test_create_form_shares_default_price_only_with_authorized_users_and_keeps_setup_cost_hidden_from_unauthorized_payload(): void
    {
        $setting = $this->settingByCode('UV_PRINT');
        $setting->forceFill([
            'custom_name' => 'Quote Varsayilan Fiyat UV',
            'default_unit_price' => 17.45,
            'default_setup_cost' => 88.90,
            'default_currency' => 'USD',
        ])->save();

        $adminResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $adminResponse->assertOk();
        $adminResponse->assertSee('Quote Varsayilan Fiyat UV');
        $adminResponse->assertSee('17.45', false);
        $adminResponse->assertSee('88.9', false);
        $adminResponse->assertSee('Baski fiyati varsayilan para birimi farkli olabilir.', false);

        $productionResponse = $this->actingAs($this->productionUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $productionResponse->assertOk();
        $productionResponse->assertSee('Quote Varsayilan Fiyat UV');
        $productionResponse->assertDontSee('17.45', false);
        $productionResponse->assertDontSee('88.9', false);
    }

    public function test_quote_form_contains_safe_default_price_suggestion_logic_without_overwriting_manual_prices(): void
    {
        $contents = file_get_contents(resource_path('views/admin/promotion-quotes/_form-workspace.blade.php'));

        $this->assertStringContainsString("function applyTenantPrintSettingPriceSuggestion(printOperation, setting = null)", $contents);
        $this->assertStringContainsString("const hasSuggestedPrice = unitPriceInput.dataset.priceSuggested === '1';", $contents);
        $this->assertStringContainsString("if (!isEmpty && !hasSuggestedPrice) {", $contents);
        $this->assertStringContainsString("unitPriceInput.dataset.priceSuggested = '1';", $contents);
        $this->assertStringContainsString("event.target.dataset.priceSuggested = '0';", $contents);
        $this->assertStringContainsString('data-price-suggested="${printRow._price_suggested ? \'1\' : \'0\'}"', $contents);
        $this->assertStringContainsString('Baski fiyati varsayilan para birimi farkli olabilir.', $contents);
    }

    public function test_store_edit_and_conversion_preserve_manual_print_price_totals_and_keep_default_setup_cost_passive(): void
    {
        $setting = $this->settingByCode('HOT_STAMPING');
        $setting->forceFill([
            'custom_name' => 'Setup Maliyetli Sicak Baski',
            'default_unit_price' => 3.25,
            'default_setup_cost' => 150.00,
            'default_currency' => 'TRY',
            'requires_setup' => true,
            'requires_production' => true,
            'setup_types' => [OrderItemPrintSetupRequirement::TYPE_CLICHE],
        ])->save();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), $this->baseQuotePayload([
                'items' => [[
                    'product_name' => 'Varsayilan Fiyat Test Urunu',
                    'product_code' => 'QPDP-001',
                    'quantity' => '40',
                    'unit' => 'Adet',
                    'list_price' => '12',
                    'discount_rate' => '0',
                    'unit_price' => '12',
                    'manual_unit_price' => '1',
                    'vat_rate' => '20',
                    'has_print' => '1',
                    'prints' => [[
                        'tenant_print_setting_id' => $setting->id,
                        'standard_print_type_id' => $setting->standard_print_type_id,
                        'print_type' => 'Eski Sicak Baski',
                        'print_option' => 'Klişeli sıcak baskı',
                        'print_quantity' => '40',
                        'print_unit_price' => '3.25',
                    ]],
                ]],
            ]))
            ->assertRedirect();

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();
        $quote->load('items.prints');

        $print = $quote->items->firstOrFail()->prints->firstOrFail();
        $this->assertSame('3.2500', (string) $print->print_unit_price);
        $this->assertSame('130.0000', (string) $print->print_total);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.edit', $quote))
            ->assertOk();

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
        $orderPrint = $order->items->firstOrFail()->prints->firstOrFail();

        $this->assertSame('3.2500', (string) $orderPrint->print_unit_price);
        $this->assertSame('130.0000', (string) $orderPrint->print_total);
        $this->assertSame(0, OrderItemPrintSetupRequirement::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('order_id', $order->id)
            ->where('order_item_print_id', $orderPrint->id)
            ->count());
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
            'notes' => 'Quote default price suggestion test',
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
            'name' => ucfirst($roleKey) . ' Quote Default Price User',
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
