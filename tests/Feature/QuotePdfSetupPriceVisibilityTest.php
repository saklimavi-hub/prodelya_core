<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\PromotionQuotePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotePdfSetupPriceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_pdf_uses_final_setup_included_print_price_and_hides_setup_total_from_customer_output(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $visibleQuote = $this->createQuote($adminUser, $customer, 'TK-PDF-SETUP-01', true);
        $visibleHtml = app(PromotionQuotePdfService::class)->renderHtml($visibleQuote->fresh());

        $this->assertStringContainsString('5,00 TL', $visibleHtml);
        $this->assertStringContainsString('500,00 TL', $visibleHtml);
        $this->assertStringContainsString('Ürün + Baskı Toplamı: 2.300,00 TL', $visibleHtml);
        $this->assertStringContainsString('Baskı Birim Fiyatı: 18,00 TL', $visibleHtml);
        $this->assertStringContainsString('Baskı Toplamı: 1.800,00 TL', $visibleHtml);
        $this->assertStringNotContainsString('Ara eleman toplam tutarı', $visibleHtml);
        $this->assertStringNotContainsString('Ara eleman toplamı', $visibleHtml);
        $this->assertStringNotContainsString('Klişe maliyeti', $visibleHtml);
        $this->assertStringNotContainsString('setup_total_amount', $visibleHtml);
        $this->assertStringNotContainsString('base_print_unit_price', $visibleHtml);

        $hiddenQuote = $this->createQuote($adminUser, $customer, 'TK-PDF-SETUP-02', false);
        $hiddenHtml = app(PromotionQuotePdfService::class)->renderHtml($hiddenQuote->fresh());

        $this->assertStringContainsString('23,00 TL', $hiddenHtml);
        $this->assertStringContainsString('2.300,00 TL', $hiddenHtml);
        $this->assertStringNotContainsString('Baskı Birim Fiyatı:', $hiddenHtml);
        $this->assertStringNotContainsString('Baskı Toplamı:', $hiddenHtml);
        $this->assertStringNotContainsString('Ara eleman toplam tutarı', $hiddenHtml);
        $this->assertStringNotContainsString('Ara eleman toplamı', $hiddenHtml);
        $this->assertStringNotContainsString('base_print_unit_price', $hiddenHtml);
    }

    private function createQuote(User $adminUser, Company $customer, string $documentNumber, bool $showPrintPriceDetails): Order
    {
        $quote = Order::query()->create([
            'tenant_account_id' => $customer->tenant_account_id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => $documentNumber,
            'customer_company_id' => $customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => '2026-07-02',
            'valid_until' => '2026-07-09',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 2300,
            'vat_total' => 460,
            'grand_total' => 2760,
            'product_total' => 500,
            'print_total' => 1800,
            'show_print_price_details_to_customer' => $showPrintPriceDetails,
            'created_by' => $adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $quote->tenant_account_id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'PDF Setup Ürünü',
            'product_code' => 'PDF-SETUP-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'unit_price' => 5,
            'line_total' => 500,
            'has_print' => true,
            'print_total' => 1800,
            'status' => 'pending',
            'price_snapshot' => [
                'product_total' => 500,
                'vat_rate' => 20,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 100, 'scope' => 'product'],
                    ['rate' => 20, 'total' => 360, 'scope' => 'print'],
                ],
            ],
        ]);

        $item->prints()->create([
            'tenant_account_id' => $quote->tenant_account_id,
            'order_id' => $quote->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Klişeli Baskı',
            'cliche_status' => 'Yeni üretilecek',
            'setup_pricing_enabled' => true,
            'setup_type' => 'cliche',
            'setup_status' => 'Yeni üretilecek',
            'setup_total_amount' => 800,
            'setup_distribution_quantity' => 100,
            'setup_unit_amount' => 8,
            'base_print_unit_price' => 10,
            'print_quantity' => 100,
            'print_unit_price' => 18,
            'print_total' => 1800,
            'note' => 'BASKI ADI: 55555555',
            'status' => 'draft',
        ]);

        return $quote;
    }
}
