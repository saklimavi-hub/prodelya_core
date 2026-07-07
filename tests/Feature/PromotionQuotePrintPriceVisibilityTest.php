<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuotePrintPriceVisibilityTest extends TestCase
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

    public function test_create_screen_shows_select_and_store_persists_false_and_edit_preserves_value(): void
    {
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $create = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $create->assertOk()
            ->assertSee('Baskı fiyatı gösterimi')
            ->assertSee('Baskı fiyatı gösterilsin')
            ->assertSee('Baskı fiyatı gizlensin')
            ->assertDontSee('Müşteri çıktılarında baskı fiyatını göster')
            ->assertSee('show_print_price_details_to_customer', false);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-07-01',
                'valid_until' => '2026-07-08',
                'invoice_status' => 'fis',
                'currency' => 'TL',
                'show_print_price_details_to_customer' => '0',
                'items' => [[
                    'product_name' => 'Print Visibility Ürünü',
                    'product_code' => 'PV-001',
                    'quantity' => '100',
                    'unit' => 'Adet',
                    'unit_price' => '5',
                    'list_price' => '5',
                    'discount_rate' => '0',
                    'manual_unit_price' => '1',
                    'vat_rate' => '0',
                    'has_print' => '1',
                    'prints' => [[
                        'print_type' => 'UV Baskı',
                        'print_option' => 'Tek taraf',
                        'print_quantity' => '100',
                        'print_unit_price' => '10',
                        'note' => 'Baskı notu',
                    ]],
                ]],
            ]);

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();

        $response->assertRedirect(route('admin.promotion-quotes.show', $quote));
        $this->assertFalse($quote->fresh()->shouldShowPrintPriceDetailsToCustomer());

        $edit = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.edit', $quote));

        $edit->assertOk()
            ->assertSee('Baskı fiyatı gösterimi')
            ->assertSee('Baskı fiyatı gizlensin')
            ->assertDontSee('Müşteri çıktılarında baskı fiyatını göster')
            ->assertSee('show_print_price_details_to_customer', false);
    }
}
