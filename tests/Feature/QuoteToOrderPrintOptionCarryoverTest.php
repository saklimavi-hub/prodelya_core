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

class QuoteToOrderPrintOptionCarryoverTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_quote_to_order_carries_tenant_print_option_and_legacy_label(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $tenant = TenantAccount::query()->firstOrFail();
        $customer = Company::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        app(TenantPrintSettingSyncService::class)->syncForTenant($tenant);
        $setting = TenantPrintSetting::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereHas('standardPrintType', fn ($query) => $query->where('code', 'UV_PRINT'))
            ->firstOrFail();
        app(TenantPrintOptionService::class)->ensureDefaultsForSetting($setting);
        $option = TenantPrintOption::query()
            ->where('tenant_print_setting_id', $setting->id)
            ->where('code', 'uv-double')
            ->firstOrFail();

        $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-07-02',
                'valid_until' => '2026-07-09',
                'invoice_status' => 'fatura',
                'currency' => 'TL',
                'delivery_type' => 'Kargo',
                'items' => [[
                    'product_name' => 'Carryover Ürün',
                    'product_code' => 'POC-001',
                    'quantity' => '20',
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
                        'tenant_print_option_id' => $option->id,
                        'print_type' => $setting->displayName(),
                        'print_option' => $option->name,
                        'print_quantity' => '20',
                        'print_unit_price' => '2.50',
                    ]],
                ]],
            ])
            ->assertRedirect();

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();

        $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.orders.convert.from.quote', $quote))
            ->assertRedirect();

        $order = Order::query()
            ->where('document_type', 'order')
            ->where('source_quote_id', $quote->id)
            ->latest('id')
            ->firstOrFail();

        $orderPrint = $order->items()->with('prints')->firstOrFail()->prints->firstOrFail();
        $this->assertSame($option->id, $orderPrint->tenant_print_option_id);
        $this->assertSame($option->name, $orderPrint->print_option);
    }
}
