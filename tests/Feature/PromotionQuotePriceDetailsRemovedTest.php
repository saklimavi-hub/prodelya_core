<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuotePriceDetailsRemovedTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_quote_edit_workspace_removes_price_details_dom_but_keeps_internal_snapshot_payload(): void
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
            'document_number' => 'TK-F1P2H3-NODETAIL-001',
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
            'subtotal' => 586.16,
            'vat_total' => 0,
            'grand_total' => 586.16,
            'product_total' => 586.16,
            'print_total' => 0,
            'created_by' => $adminUser->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'manual',
            'product_name' => 'NodeTail USD Ürün',
            'product_code' => 'F1P2H3-DETAILS-REMOVED',
            'quantity' => 1,
            'unit' => 'Adet',
            'price_snapshot' => [
                'source_price' => 12.50,
                'source_currency' => 'USD',
                'base_price' => 586.16,
                'document_currency' => 'TRY',
                'source_to_base_rate' => 46.8928,
                'source_to_base_rate_date' => '2026-07-10',
                'source_to_base_rate_source' => 'tcmb',
                'manual_unit_price' => true,
            ],
            'stock_snapshot' => [
                'visible_stock_quantity' => 7000,
                'supplier_stock_quantity' => 7000,
            ],
            'list_price' => 586.16,
            'discount_rate' => 0,
            'unit_price' => 586.16,
            'calculated_unit_price' => 586.16,
            'line_total' => 586.16,
            'has_print' => false,
            'print_total' => 0,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.edit', $quote));

        $response->assertOk();
        $response->assertDontSee('Fiyat ayrıntısı');
        $response->assertDontSee('pd-quote-price-details', false);
        $response->assertSee('source_to_base_rate', false);
        $response->assertSee('source_to_base_rate_date', false);
        $response->assertSee('source_to_base_rate_source', false);
        $response->assertSee('manual_unit_price', false);
        $response->assertSee('price_snapshot', false);
    }
}
