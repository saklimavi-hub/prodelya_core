<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\PromotionQuotePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotePdfCompactLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_pdf_renders_compact_customer_layout_without_qr_and_product_code(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $quote = Order::query()->create([
            'tenant_account_id' => $customer->tenant_account_id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-PDF-COMPACT-01',
            'customer_company_id' => $customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => '2026-07-02',
            'valid_until' => '2026-07-09',
            'invoice_status' => 'fatura',
            'delivery_type' => 'Ofis Teslim',
            'currency' => 'TL',
            'subtotal' => 900,
            'vat_total' => 180,
            'grand_total' => 1080,
            'product_total' => 600,
            'print_total' => 300,
            'created_by' => $adminUser->id,
            'notes' => 'Kompakt PDF test notu',
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => $quote->tenant_account_id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Kompakt PDF Ürünü',
            'product_code' => 'COMPACT-001',
            'quantity' => 100,
            'unit' => 'Adet',
            'unit_price' => 6,
            'line_total' => 600,
            'has_print' => true,
            'print_total' => 300,
            'status' => 'pending',
            'price_snapshot' => [
                'product_total' => 600,
                'vat_rate' => 20,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 120, 'scope' => 'product'],
                    ['rate' => 20, 'total' => 60, 'scope' => 'print'],
                ],
            ],
        ]);

        $item->prints()->create([
            'tenant_account_id' => $quote->tenant_account_id,
            'order_id' => $quote->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Tek taraf baskı',
            'print_quantity' => 100,
            'print_unit_price' => 3,
            'print_total' => 300,
            'note' => 'baskı notu',
            'status' => 'draft',
        ]);

        $html = app(PromotionQuotePdfService::class)->renderHtml($quote->fresh());

        $this->assertStringContainsString('Promosyon Teklifi', $html);
        $this->assertStringContainsString('Ürün ve Baskı Kalemleri', $html);
        $this->assertStringContainsString('Ofis Teslim', $html);
        $this->assertStringContainsString('Hazırlayan', $html);
        $this->assertStringContainsString('Müşteri Onayı', $html);
        $this->assertStringContainsString('QR kod bu dokümanda yer almaz.', $html);
        $this->assertStringNotContainsString('COMPACT-001', $html);
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('data:image/svg+xml', $html);
    }
}
