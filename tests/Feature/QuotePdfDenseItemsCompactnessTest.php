<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\PromotionQuotePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotePdfDenseItemsCompactnessTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_pdf_supports_dense_items_without_sensitive_fields_and_keeps_compact_layout(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $quote = Order::query()->create([
            'tenant_account_id' => $customer->tenant_account_id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-PDF-DENSE-01',
            'customer_company_id' => $customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => '2026-07-03',
            'valid_until' => '2026-07-10',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Ofis Teslim',
            'currency' => 'TL',
            'subtotal' => 4200,
            'vat_total' => 840,
            'grand_total' => 5040,
            'product_total' => 2800,
            'print_total' => 1400,
            'show_print_price_details_to_customer' => false,
            'created_by' => $adminUser->id,
            'notes' => 'Yoğun PDF test notu',
        ]);

        for ($i = 1; $i <= 7; $i++) {
            $item = OrderItem::query()->create([
                'tenant_account_id' => $quote->tenant_account_id,
                'order_id' => $quote->id,
                'item_type' => 'product',
                'product_source' => 'manual',
                'product_name' => 'Yoğun PDF Ürünü ' . $i,
                'product_code' => 'DENSE-' . $i,
                'quantity' => 100,
                'unit' => 'Adet',
                'description' => 'Yoğun PDF test ürün açıklaması',
                'unit_price' => 4,
                'line_total' => 400,
                'has_print' => true,
                'print_total' => 200,
                'status' => 'pending',
                'price_snapshot' => [
                    'product_total' => 400,
                    'vat_rate' => 20,
                    'vat_breakdown' => [
                        ['rate' => 20, 'total' => 80, 'scope' => 'product'],
                        ['rate' => 20, 'total' => 40, 'scope' => 'print'],
                    ],
                    'supplier_cost' => 99,
                    'group_code' => 'SECRET',
                    'projection' => 'hidden',
                ],
            ]);

            $item->prints()->create([
                'tenant_account_id' => $quote->tenant_account_id,
                'order_id' => $quote->id,
                'order_item_id' => $item->id,
                'print_type' => 'UV Baskı',
                'print_option' => 'Tek taraf',
                'print_quantity' => 100,
                'print_unit_price' => 2,
                'print_total' => 200,
                'note' => 'BASKI SATIRI ' . $i,
                'setup_pricing_enabled' => true,
                'setup_total_amount' => 50,
                'status' => 'draft',
            ]);
        }

        $html = app(PromotionQuotePdfService::class)->renderHtml($quote->fresh());

        $this->assertStringContainsString('Promosyon Teklifi', $html);
        $this->assertStringContainsString('Ürün ve Baskı Kalemleri', $html);
        $this->assertStringContainsString('@page { margin: 18px 20px; }', $html);
        $this->assertStringContainsString('Müşteri teklif özeti', $html);
        $this->assertStringContainsString('Yoğun PDF Ürünü 7', $html);
        $this->assertStringNotContainsString('Baskı Birim:', $html);
        $this->assertStringNotContainsString('Baskı Toplam:', $html);
        $this->assertStringNotContainsString('setup_total_amount', $html);
        $this->assertStringNotContainsString('supplier_cost', $html);
        $this->assertStringNotContainsString('group_code', $html);
        $this->assertStringNotContainsString('projection', $html);
    }
}
