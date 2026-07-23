<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteSalesListCurrencyUiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_quote_edit_workspace_surfaces_final_compact_sales_meta_and_snapshot_payload(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $tenant = TenantAccount::query()->firstOrFail();
        $customer = Company::query()
            ->where('tenant_account_id', $tenant->id)
            ->firstOrFail();

        $quote = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-F1P2-UI-001',
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
            'product_name' => 'USD KaynaklÄ± SatÄ±ÅŸ ÃœrÃ¼nÃ¼',
            'product_code' => 'F1P2-USD-001',
            'quantity' => 1,
            'unit' => 'Adet',
            'product_snapshot' => ['product_name' => 'USD KaynaklÄ± SatÄ±ÅŸ ÃœrÃ¼nÃ¼'],
            'price_snapshot' => [
                'source_price' => 7.75,
                'source_currency' => 'USD',
                'base_price' => 364.22,
                'base_cost' => 364.22,
                'document_currency' => 'TRY',
                'source_to_base_rate' => 46.9961,
                'source_to_base_rate_date' => '2026-07-14',
                'source_to_base_rate_source' => 'tcmb',
                'suggested_sales_unit_price_document' => 200.321,
                'actual_sales_unit_price_document' => 200.321,
                'calculated_unit_price' => 200.321,
                'manual_sales_price_override' => false,
                'applied_rate' => 46.9961,
                'rate_date' => '2026-07-14',
                'rate_source' => 'tcmb',
                'document_conversion_status' => 'converted',
                'sales_presentation' => [
                    'sales_source_amount' => 7.75,
                    'sales_source_currency' => 'USD',
                    'sales_rate' => 46.9961,
                    'sales_rate_date' => '2026-07-14',
                    'sales_rate_source' => 'tcmb',
                    'sales_list_try' => 364.22,
                    'sales_discount_percent' => 45,
                    'sales_calculated_unit_try' => 200.321,
                    'sales_final_unit_try' => 200.321,
                    'sales_manual_override' => false,
                    'sales_document_currency' => 'TRY',
                    'conversion_status' => 'converted',
                    'fallback_used' => false,
                    'stale' => false,
                ],
            ],
            'list_price' => 364.22,
            'discount_rate' => 45,
            'unit_price' => 200.321,
            'line_total' => 200.321,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.edit', $quote));

        $response->assertOk();
        $response->assertSee('Satış Liste');
        $response->assertSee('Satış Birim Fiyatı');
        $response->assertDontSee('Hesaplanan Birim');
        $response->assertDontSee('Nihai Satış Birim');
        $response->assertDontSee('Satış liste fiyatı');
        $response->assertDontSee('Hesaplanan satış birim fiyatı');
        $response->assertDontSee('Nihai satış birim fiyatı');
        $response->assertDontSee('Fiyat ayrıntısı');
        $response->assertDontSee('pd-quote-price-details', false);
        $response->assertSee('buildCompactProductMetaLine(item, payload)', false);
        $response->assertSee('buildCompactProductMetaLine(entry, entry, { includePrice: true })', false);
        $response->assertSee('class="pd-product-live-info__meta-line"', false);
        $response->assertDontSee('pd-product-live-info__meta-row', false);
        $response->assertDontSee('pd-product-live-info__meta-bit', false);
        $response->assertSee('"source_price":7.75', false);
        $response->assertSee('"source_currency":"USD"', false);
        $response->assertSee('"base_price":364.22', false);
        $response->assertSee('"source_to_base_rate":46.9961', false);
        $response->assertSee('"source_to_base_rate_date":"2026-07-14"', false);
        $response->assertSee('"source_to_base_rate_source":"tcmb"', false);
        $response->assertSee('"calculated_unit_price":200.321', false);
        $response->assertSee('"manual_sales_price_override":false', false);
        $response->assertDontSee('purchase_source_amount', false);
        $response->assertDontSee('purchase_source_currency', false);
        $response->assertDontSee('purchase_unit_price', false);
        $response->assertDontSee('supplier cost');
        $response->assertDontSee('current account');
        $response->assertDontSee('file_path');
        $response->assertDontSee('data-token=', false);
    }
}
