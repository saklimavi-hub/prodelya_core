<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteTopInfoLayoutPolishTest extends TestCase
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

    public function test_create_screen_uses_correct_klise_spelling_and_top_info_select_layout(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertDontSee('KlişE');
        $response->assertSee('Klişe');
        $response->assertSee('Baskı fiyatı gösterimi');
        $response->assertSee('Baskı fiyatı gösterilsin');
        $response->assertSee('Baskı fiyatı gizlensin');
        $response->assertDontSee('Müşteri çıktılarında baskı fiyatını göster');
        $response->assertSeeInOrder([
            'Teklif tarihi',
            'Teslim tarihi',
            'Teklif Durumu',
            'Belge Türü',
            'Teslimat Tipi',
            'Para birimi',
            'Baskı fiyatı gösterimi',
            'Sipariş Notu',
        ]);
    }

    public function test_select_visibility_value_round_trips_through_store_and_edit(): void
    {
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $storeResponse = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.promotion-quotes.store'), [
                'customer_company_id' => $customer->id,
                'quote_date' => '2026-07-01',
                'valid_until' => '2026-07-08',
                'invoice_status' => 'fis',
                'currency' => 'TL',
                'show_print_price_details_to_customer' => '1',
                'items' => [[
                    'product_name' => 'Top Info Ürünü',
                    'product_code' => 'TOP-001',
                    'quantity' => '10',
                    'unit' => 'Adet',
                    'unit_price' => '5',
                    'list_price' => '5',
                    'discount_rate' => '0',
                    'manual_unit_price' => '1',
                    'vat_rate' => '0',
                    'has_print' => '0',
                    'prints' => [],
                ]],
            ]);

        $quote = Order::query()->where('document_type', 'quote')->latest('id')->firstOrFail();

        $storeResponse->assertRedirect(route('admin.promotion-quotes.show', $quote));
        $this->assertTrue($quote->fresh()->shouldShowPrintPriceDetailsToCustomer());

        $edit = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.edit', $quote));

        $edit->assertOk()
            ->assertSee('Baskı fiyatı gösterimi')
            ->assertSee('value="1" selected', false);
    }
}
