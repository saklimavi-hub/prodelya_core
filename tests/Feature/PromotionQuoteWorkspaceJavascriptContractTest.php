<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteWorkspaceJavascriptContractTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_quote_create_workspace_renders_canonical_initializer_and_compact_helpers(): void
    {
        $adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('id="quote-customer-search"', false);
        $response->assertSee('id="add-product-item"', false);
        $response->assertSee("document.addEventListener('DOMContentLoaded', () => {", false);
        $response->assertSee("document.getElementById('add-product-item')?.addEventListener('click', addProductItem);", false);
        $response->assertSee("document.getElementById('quote-customer-search')?.addEventListener('input', (event) => {", false);
        $response->assertSee("function buildValidationSummaryLabel(type, itemIndex, printIndex = null) {", false);
        $response->assertSee('return `Ürün ${itemIndex + 1} / Baskı ${itemIndex + 1}${String.fromCharCode(97 + printIndex)}`;', false);
        $response->assertSee('return `Ürün ${itemIndex + 1}`;', false);
        $response->assertDontSee('return Ürün  / Baskı ;', false);
        $response->assertDontSee('return Ürün ;', false);
        $response->assertSee('buildCompactProductMetaLine(item, payload)', false);
        $response->assertSee('buildCompactProductMetaLine(entry, entry, { includePrice: true })', false);
    }

    public function test_quote_edit_workspace_keeps_compact_meta_contract_without_legacy_meta_bits(): void
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
            'document_number' => 'TK-R3-JS-001',
            'customer_company_id' => $customer->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'customer_approval_status' => 'not_sent',
            'quote_date' => '2026-07-17',
            'valid_until' => '2026-07-24',
            'invoice_status' => 'fis',
            'currency' => 'TRY',
            'tenant_base_currency' => 'TRY',
            'currency_policy' => 'multi_currency_draft',
            'subtotal' => 0,
            'vat_total' => 0,
            'grand_total' => 0,
            'product_total' => 0,
            'print_total' => 0,
            'created_by' => $adminUser->id,
        ]);

        $response = $this->actingAs($adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.edit', $quote));

        $response->assertOk();
        $response->assertSee('class="pd-product-live-info__meta-line"', false);
        $response->assertDontSee('pd-product-live-info__meta-row', false);
        $response->assertDontSee('pd-product-live-info__meta-bit', false);
        $response->assertSee('buildCompactProductMetaLine(item, payload)', false);
        $response->assertSee('buildCompactProductMetaLine(entry, entry, { includePrice: true })', false);
    }
}
