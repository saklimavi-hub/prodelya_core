<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteCompactSelectedProductMetaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_quote_edit_workspace_uses_image_plus_compact_meta_without_duplicate_title_or_details_panel(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $tenant = TenantAccount::query()->firstOrFail();
        $customer = Company::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('legal_name', 'ABC İnşaat A.Ş.')
            ->firstOrFail();

        $quote = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-F1P2H3-COMPACT-001',
            'customer_company_id' => $customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => 'not_sent',
            'quote_date' => '2026-07-14',
            'valid_until' => '2026-07-21',
            'invoice_status' => 'fis',
            'currency' => 'TRY',
            'tenant_base_currency' => 'TRY',
            'currency_policy' => 'multi_currency_draft',
            'subtotal' => 200.32,
            'vat_total' => 0,
            'grand_total' => 200.32,
            'product_total' => 200.32,
            'print_total' => 0,
            'created_by' => $adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'USD Kaynaklı Kompakt Ürün',
            'product_code' => 'F1P2H3-USD-001',
            'quantity' => 1,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => 'USD Kaynaklı Kompakt Ürün',
                'image_url' => 'https://example.test/f1p2h3-compact.jpg',
            ],
            'price_snapshot' => [
                'source_price' => 9.75,
                'source_currency' => 'USD',
                'base_price' => 457.20,
                'base_cost' => 457.20,
                'document_currency' => 'TRY',
                'suggested_sales_unit_price_document' => 251.46,
                'actual_sales_unit_price_document' => 251.46,
                'manual_sales_price_override' => false,
                'source_to_base_rate' => 46.8923,
                'source_to_base_rate_date' => '2026-07-12',
                'source_to_base_rate_source' => 'tcmb',
                'document_conversion_status' => 'converted',
                'sales_presentation' => [
                    'sales_source_amount' => 9.75,
                    'sales_source_currency' => 'USD',
                    'sales_rate' => 46.8923,
                    'sales_rate_date' => '2026-07-12',
                    'sales_rate_source' => 'tcmb',
                    'sales_list_try' => 457.20,
                    'sales_discount_percent' => 45,
                    'sales_calculated_unit_try' => 251.46,
                    'sales_final_unit_try' => 251.46,
                    'sales_manual_override' => false,
                    'sales_document_currency' => 'TRY',
                    'conversion_status' => 'converted',
                    'fallback_used' => false,
                    'stale' => false,
                ],
            ],
            'stock_snapshot' => [
                'visible_stock_quantity' => 7000,
                'supplier_stock_quantity' => 7000,
            ],
            'list_price' => 457.20,
            'discount_rate' => 45,
            'unit_price' => 251.46,
            'calculated_unit_price' => 251.46,
            'line_total' => 251.46,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.edit', $quote));

        $response->assertOk();
        $response->assertSee('Satış Birim Fiyatı');
        $response->assertDontSee('Hesaplanan Birim');
        $response->assertSee('buildCompactProductMetaBits', false);
        $response->assertSee('buildCompactProductMetaLine(item, payload)', false);
        $response->assertSee('buildCompactProductMetaLine(entry, entry, { includePrice: true })', false);
        $response->assertDontSee('Satış liste:', false);
        $response->assertDontSee('TL karşılığı:', false);
        $response->assertDontSee('Kur:', false);
        $response->assertSee('Güncellendi:', false);
        $response->assertDontSee('Fiyat ayrıntısı');
        $response->assertDontSee('pd-quote-price-details', false);
        $response->assertDontSee('pd-quote-line-title', false);
        $response->assertDontSee('pd-quote-subtle-bit', false);
        $response->assertDontSee('alt="Ürün görseli"', false);
        $response->assertSee('alt="${escapeHtml(item.product_name || `Ürün ${item._index + 1}`)}"', false);
    }
}
