<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteShowDecisionScreenTest extends TestCase
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

    public function test_show_screen_uses_simple_quote_language_and_displays_product_print_totals_without_vat_rows_when_zero(): void
    {
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $quote = $this->createPromotionQuote($customer, [
            'document_number' => 'TK-2026-5101',
            'status' => 'draft',
            'workflow_status' => 'quote',
            'invoice_status' => 'fis',
            'subtotal' => 2600,
            'vat_total' => 0,
            'grand_total' => 2600,
            'product_total' => 2500,
            'print_total' => 100,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote));

        $response->assertOk();
        $response->assertDontSee('Taslak');
        $response->assertDontSee('Teklif Hazırlanıyor');
        $response->assertSee('Teklif');
        $response->assertSee('YN-2204-GRI Isıkliter Gun Metal USB Bellek');
        $response->assertSee('YN-2204-GRI');
        $response->assertSee('10 Adet');
        $response->assertSee('Birim Fiyat');
        $response->assertSee('Toplam');
        $response->assertSee('Lazer');
        $response->assertSee('İsim lazer');
        $response->assertSee('Baskı Satırı');
        $response->assertSee('10 Adet');
        $response->assertSee('Baskı Toplamı');
        $response->assertSee('Deneme baskı');
        $response->assertSee('Ürün Toplamı');
        $response->assertSee('Baskı Toplamı');
        $response->assertSee('Ara Toplam');
        $response->assertSee('Genel Toplam');
        $response->assertDontSee('KDV Yok');
        $response->assertDontSee('KDV Toplamı');
        $response->assertDontSee('- -');
        $response->assertDontSee('group_code', false);
        $response->assertDontSee('snapshot_json', false);
        $response->assertDontSee('quote_send_snapshot_id', false);
        $response->assertDontSee('raw_price_snapshot', false);
        $response->assertDontSee('data-testid="quote-convert-cta"', false);
        $response->assertSee('Onaylandı İşaretle');
    }

    public function test_show_screen_displays_vat_rows_when_quote_has_vat(): void
    {
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $quote = $this->createPromotionQuote($customer, [
            'document_number' => 'TK-2026-5102',
            'status' => 'approved',
            'workflow_status' => 'quote',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_APPROVED,
            'invoice_status' => 'fatura',
            'subtotal' => 2600,
            'vat_total' => 520,
            'grand_total' => 3120,
            'product_total' => 2500,
            'print_total' => 100,
        ], true);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote));

        $response->assertOk();
        $response->assertSee('Onaylandı');
        $response->assertSee('KDV %20');
        $response->assertSee('KDV Toplamı');
        $response->assertSee('Genel Toplam');
        $response->assertSee('data-testid="quote-convert-cta"', false);
    }

    public function test_show_screen_for_converted_quote_prefers_open_order_action(): void
    {
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $quote = $this->createPromotionQuote($customer, [
            'document_number' => 'TK-2026-5103',
            'status' => 'approved',
            'workflow_status' => 'quote_converted',
            'customer_approval_status' => Order::CUSTOMER_APPROVAL_APPROVED,
        ]);

        $order = Order::query()->create([
            'tenant_account_id' => 1,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'order',
            'document_number' => 'SP-2026-0901',
            'source_quote_id' => $quote->id,
            'source_quote_number' => $quote->document_number,
            'customer_company_id' => $customer->id,
            'status' => 'pending',
            'workflow_status' => 'order_created',
            'quote_date' => now()->toDateString(),
            'invoice_status' => 'fis',
            'currency' => 'TL',
            'subtotal' => 2600,
            'vat_total' => 0,
            'grand_total' => 2600,
            'product_total' => 2500,
            'print_total' => 100,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.show', $quote->fresh()));

        $response->assertOk();
        $response->assertSee('Siparişe Dönüştü');
        $response->assertSee('Siparişi Aç');
        $response->assertSee(route('admin.orders.show', $order), false);
        $response->assertDontSee('data-testid="quote-convert-cta"', false);
    }

    private function createPromotionQuote(Company $customer, array $overrides = [], bool $withVat = false): Order
    {
        $quote = Order::query()->create(array_merge([
            'tenant_account_id' => $customer->tenant_account_id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-2026-'.str_pad((string) random_int(5000, 9999), 4, '0', STR_PAD_LEFT),
            'customer_company_id' => $customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => now()->toDateString(),
            'invoice_status' => 'fis',
            'currency' => 'TL',
            'subtotal' => 2600,
            'vat_total' => 0,
            'grand_total' => 2600,
            'product_total' => 2500,
            'print_total' => 100,
            'created_by' => $this->adminUser->id,
        ], $overrides));

        $priceSnapshot = [
            'vat_mode' => $withVat ? 'taxable' : 'none',
            'vat_rate' => $withVat ? 20 : 0,
            'product_line_total' => 2500,
            'product_vat_total' => $withVat ? 500 : 0,
            'print_vat_rate' => $withVat ? 20 : 0,
            'print_vat_total' => $withVat ? 20 : 0,
            'vat_breakdown' => $withVat ? [
                ['rate' => 20, 'total' => 520],
            ] : [],
        ];

        $item = OrderItem::query()->create([
            'tenant_account_id' => $quote->tenant_account_id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'YN-2204-GRI Isıkliter Gun Metal USB Bellek',
            'product_code' => 'YN-2204-GRI',
            'quantity' => 10,
            'unit' => 'Adet',
            'line_total' => 2500,
            'unit_price' => 250,
            'has_print' => true,
            'print_total' => 100,
            'status' => 'pending',
            'description' => 'Ön yüz ürün notu',
            'product_snapshot' => ['warning_badges' => []],
            'price_snapshot' => $priceSnapshot,
            'stock_snapshot' => ['supplier_stock_quantity' => 500],
        ]);

        $item->prints()->create([
            'tenant_account_id' => $quote->tenant_account_id,
            'order_id' => $quote->id,
            'order_item_id' => $item->id,
            'print_type' => 'Lazer',
            'print_option' => 'İsim lazer',
            'production_type' => 'İç üretim',
            'print_quantity' => 10,
            'print_unit_price' => 10,
            'print_total' => 100,
            'note' => 'Deneme baskı',
            'status' => 'draft',
        ]);

        $item->prints()->create([
            'tenant_account_id' => $quote->tenant_account_id,
            'order_id' => $quote->id,
            'order_item_id' => $item->id,
            'print_type' => null,
            'print_option' => null,
            'print_quantity' => 0,
            'print_unit_price' => 0,
            'print_total' => 0,
            'note' => null,
            'status' => 'draft',
        ]);

        return $quote;
    }
}
