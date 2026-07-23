<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\PromotionQuotePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotePdfCustomerPriceDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_pdf_switches_between_separate_and_combined_customer_price_presentations(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $visibleQuote = $this->createQuote($adminUser, $customer, 'TK-PDF-CUST-01', true);
        $visibleHtml = app(PromotionQuotePdfService::class)->renderHtml($visibleQuote->fresh());

        $this->assertStringContainsString('5,00 TL', $visibleHtml);
        $this->assertStringContainsString('500,00 TL', $visibleHtml);
        $this->assertStringContainsString('Ürün Birim Fiyatı', $visibleHtml);
        $this->assertStringContainsString('Ürün Toplamı', $visibleHtml);
        $this->assertStringContainsString('Ürün + Baskı Toplamı: 1.500,00 TL', $visibleHtml);
        $this->assertStringContainsString('UV Baskı · Çift Taraf Baskı · 100 Adet · BASKI ADI: 55555555 · Baskı Birim Fiyatı: 10,00 TL · Baskı Toplamı: 1.000,00 TL', $visibleHtml);
        $this->assertStringNotContainsString('PDF-FIYAT-001', $visibleHtml);

        $hiddenQuote = $this->createQuote($adminUser, $customer, 'TK-PDF-CUST-02', false);
        $hiddenHtml = app(PromotionQuotePdfService::class)->renderHtml($hiddenQuote->fresh());

        $this->assertStringContainsString('15,00 TL', $hiddenHtml);
        $this->assertStringContainsString('1.500,00 TL', $hiddenHtml);
        $this->assertStringContainsString('Baskı Dahil Birim Fiyat', $hiddenHtml);
        $this->assertStringContainsString('Baskı Dahil Satır Toplamı', $hiddenHtml);
        $this->assertStringContainsString('UV Baskı · Çift Taraf Baskı · 100 Adet · BASKI ADI: 55555555', $hiddenHtml);
        $this->assertStringNotContainsString('Baskı Birim Fiyatı:', $hiddenHtml);
        $this->assertStringNotContainsString('Baskı Toplamı:', $hiddenHtml);
        $this->assertStringNotContainsString('PDF-FIYAT-001', $hiddenHtml);
    }

    public function test_pdf_renders_multi_print_information_inline_without_double_counting_totals(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $quote = $this->createQuote($adminUser, $customer, 'TK-PDF-CUST-03', true);
        $item = $quote->items()->firstOrFail();

        $item->prints()->create([
            'tenant_account_id' => $quote->tenant_account_id,
            'order_id' => $quote->id,
            'order_item_id' => $item->id,
            'print_type' => 'Sıcak Baskı',
            'print_option' => 'Klişeli sıcak baskı',
            'print_quantity' => 100,
            'print_unit_price' => 2,
            'print_total' => 200,
            'note' => 'sıcak baskı',
            'status' => 'draft',
        ]);

        $quote->forceFill([
            'subtotal' => 1700,
            'vat_total' => 340,
            'grand_total' => 2040,
            'print_total' => 1200,
        ])->save();

        $item->forceFill([
            'print_total' => 1200,
        ])->save();

        $html = app(PromotionQuotePdfService::class)->renderHtml($quote->fresh());

        $this->assertStringContainsString('5,00 TL', $html);
        $this->assertStringContainsString('500,00 TL', $html);
        $this->assertStringContainsString('Ürün + Baskı Toplamı: 1.700,00 TL', $html);
        $this->assertStringContainsString('UV Baskı · Çift Taraf Baskı · 100 Adet · BASKI ADI: 55555555 · Baskı Birim Fiyatı: 10,00 TL · Baskı Toplamı: 1.000,00 TL', $html);
        $this->assertStringContainsString('Sıcak Baskı · Klişeli sıcak baskı · 100 Adet · sıcak baskı · Baskı Birim Fiyatı: 2,00 TL · Baskı Toplamı: 200,00 TL', $html);
        $this->assertStringContainsString('<span class="segment"> | </span>', $html);
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
            'quote_date' => '2026-07-01',
            'valid_until' => '2026-07-08',
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 1500,
            'vat_total' => 300,
            'grand_total' => 1800,
            'product_total' => 500,
            'print_total' => 1000,
            'show_print_price_details_to_customer' => $showPrintPriceDetails,
            'created_by' => $adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $quote->tenant_account_id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'PDF Fiyat Ürünü',
            'product_code' => 'PDF-FIYAT-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'unit_price' => 5,
            'line_total' => 500,
            'has_print' => true,
            'print_total' => 1000,
            'status' => 'pending',
            'price_snapshot' => [
                'product_total' => 500,
                'vat_rate' => 20,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 100, 'scope' => 'product'],
                    ['rate' => 20, 'total' => 200, 'scope' => 'print'],
                ],
            ],
        ]);

        $item->prints()->create([
            'tenant_account_id' => $quote->tenant_account_id,
            'order_id' => $quote->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Çift Taraf Baskı',
            'print_quantity' => 100,
            'print_unit_price' => 10,
            'print_total' => 1000,
            'note' => 'BASKI ADI: 55555555',
            'status' => 'draft',
        ]);

        return $quote;
    }
}
