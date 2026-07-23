<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteSelectedRowHydrationParityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_create_and_edit_workspace_share_same_selected_row_metadata_renderer(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $customer = Company::query()->where('tenant_account_id', 1)->where('legal_name', 'ABC İnşaat A.Ş.')->firstOrFail();
        $quote = Order::query()->create([
            'tenant_account_id' => 1,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-2026-3191',
            'customer_company_id' => $customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => now()->toDateString(),
            'valid_until' => now()->addDays(7)->toDateString(),
            'invoice_status' => 'fis',
            'delivery_type' => 'Ofis Teslim',
            'delivery_type_id' => 1,
            'currency' => 'TRY',
            'subtotal' => 0,
            'vat_total' => 0,
            'grand_total' => 0,
            'product_total' => 0,
            'print_total' => 0,
            'created_by' => $adminUser->id,
        ]);

        $createResponse = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $editResponse = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.edit', $quote));

        $createResponse->assertOk();
        $editResponse->assertOk();
        $createResponse->assertSee('renderLiveProductInfoPanel(item)', false);
        $editResponse->assertSee('renderLiveProductInfoPanel(item)', false);
        $createResponse->assertSee('buildCompactProductMetaLine(item, payload)', false);
        $editResponse->assertSee('buildCompactProductMetaLine(item, payload)', false);
    }
}
