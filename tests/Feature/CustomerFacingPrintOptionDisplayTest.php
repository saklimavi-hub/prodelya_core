<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemPrint;
use App\Models\User;
use App\Services\PromotionQuotePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerFacingPrintOptionDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_pdf_keeps_print_option_label_visible_without_sensitive_data(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();

        $quote = Order::query()->create([
            'tenant_account_id' => 1,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-PRINT-OPTION-01',
            'customer_company_id' => $customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => now()->toDateString(),
            'invoice_status' => 'fatura',
            'currency' => 'TL',
            'subtotal' => 300,
            'vat_total' => 60,
            'grand_total' => 360,
            'product_total' => 100,
            'print_total' => 200,
            'show_print_price_details_to_customer' => true,
            'created_by' => $adminUser->id,
        ]);

        $item = OrderItem::query()->create([
            'tenant_account_id' => 1,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'Müşteri Facing Seçenek Ürünü',
            'product_code' => 'CF-001',
            'quantity' => 10,
            'unit' => 'Adet',
            'list_price' => 10,
            'unit_price' => 10,
            'line_total' => 100,
            'has_print' => true,
            'print_total' => 200,
            'status' => 'pending',
            'price_snapshot' => [
                'vat_mode' => 'taxable',
                'vat_rate' => 20,
                'product_line_total' => 100,
                'print_vat_rate' => 20,
                'print_vat_total' => 40,
                'product_vat_total' => 20,
                'vat_breakdown' => [
                    ['rate' => 20, 'total' => 60],
                ],
            ],
        ]);

        OrderItemPrint::query()->create([
            'tenant_account_id' => 1,
            'order_id' => $quote->id,
            'order_item_id' => $item->id,
            'print_type' => 'UV Baskı',
            'print_option' => 'Çift taraf UV baskı',
            'print_quantity' => 10,
            'print_unit_price' => 20,
            'print_total' => 200,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.pdf', $quote));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        $html = app(PromotionQuotePdfService::class)->renderHtml($quote->fresh());

        $this->assertStringContainsString('Çift taraf UV baskı', $html);
        $this->assertStringNotContainsString('supplier_cost', $html);
        $this->assertStringNotContainsString('group_code', $html);
        $this->assertStringNotContainsString('raw', $html);
    }
}
