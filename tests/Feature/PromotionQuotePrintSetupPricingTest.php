<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuotePrintSetupPricingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_create_screen_renders_setup_distribution_fields_and_store_persists_calculated_values(): void
    {
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $create = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $create->assertOk()
            ->assertSee('base_print_unit_price', false)
            ->assertSee('setup_total_amount', false)
            ->assertSee('Ara eleman toplam tutarı');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-07-02',
                'valid_until' => '2026-07-09',
                'invoice_status' => 'fis',
                'currency' => 'TL',
                'items' => [[
                    'product_name' => 'Setup Pricing Ürünü',
                    'product_code' => 'SETUP-001',
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'unit_price' => '5',
                    'list_price' => '5',
                    'discount_rate' => '0',
                    'manual_unit_price' => '1',
                    'vat_rate' => '0',
                    'has_print' => '1',
                    'prints' => [[
                        'print_type' => 'Sıcak Baskı',
                        'print_option' => 'Klişeli sıcak baskı',
                        'print_quantity' => '100',
                        'base_print_unit_price' => '10',
                        'print_unit_price' => '18',
                        'cliche_status' => 'Yeni üretilecek',
                        'setup_status' => 'Yeni üretilecek',
                        'setup_pricing_enabled' => '1',
                        'setup_type' => 'cliche',
                        'setup_total_amount' => '800',
                        'note' => 'deneme baskı',
                    ]],
                ]],
            ]);

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();
        $print = $quote->items()->firstOrFail()->prints()->firstOrFail();

        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));
        $this->assertTrue((bool) $print->setup_pricing_enabled);
        $this->assertSame('cliche', $print->setup_type);
        $this->assertSame('Yeni üretilecek', $print->setup_status);
        $this->assertEquals(800.0, (float) $print->setup_total_amount);
        $this->assertEquals(100.0, (float) $print->setup_distribution_quantity);
        $this->assertEquals(8.0, (float) $print->setup_unit_amount);
        $this->assertEquals(10.0, (float) $print->base_print_unit_price);
        $this->assertEquals(18.0, (float) $print->print_unit_price);
        $this->assertEquals(1800.0, (float) $print->print_total);

        $edit = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.edit', $quote));

        $edit->assertOk()
            ->assertSee('Ara eleman toplam tutarı')
            ->assertSee('Nihai baskı birim fiyatı')
            ->assertSee('Yeni üretilecek')
            ->assertSee('800.00', false)
            ->assertSee('10.00', false);
    }

    public function test_negative_setup_total_amount_is_rejected(): void
    {
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->from(route('admin.promotion-quotes.create'))
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-07-02',
                'valid_until' => '2026-07-09',
                'invoice_status' => 'fis',
                'currency' => 'TL',
                'items' => [[
                    'product_name' => 'Setup Validation Ürünü',
                    'product_code' => 'SETUP-NEG-001',
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'unit_price' => '5',
                    'list_price' => '5',
                    'discount_rate' => '0',
                    'manual_unit_price' => '1',
                    'vat_rate' => '0',
                    'has_print' => '1',
                    'prints' => [[
                        'print_type' => 'Sıcak Baskı',
                        'print_option' => 'Klişeli sıcak baskı',
                        'print_quantity' => '100',
                        'base_print_unit_price' => '10',
                        'cliche_status' => 'Yeni üretilecek',
                        'setup_status' => 'Yeni üretilecek',
                        'setup_pricing_enabled' => '1',
                        'setup_total_amount' => '-5',
                    ]],
                ]],
            ]);

        $response->assertRedirect(route('admin.promotion-quotes.create'));
        $response->assertSessionHasErrors();
    }
}
