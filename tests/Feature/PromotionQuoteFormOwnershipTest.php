<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteFreshnessFixtures;
use Tests\TestCase;

class PromotionQuoteFormOwnershipTest extends TestCase
{
    use BuildsQuoteFreshnessFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_create_workspace_keeps_product_container_inside_canonical_quote_form(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('form-owner-create');

        $response = $this->actingAs($fixture['user'], 'web')
            ->withServerVariables(['HTTP_HOST' => parse_url($this->tenantUrl($fixture['tenant'], '/'), PHP_URL_HOST)])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();

        $xpath = $this->parseHtml($response->getContent());
        $this->assertSame(1, $xpath->query("//form[@id='quote-form' and @data-promotion-quote-form]")->length);
        $this->assertSame(1, $xpath->query("//*[@id='product-items-container'][ancestor::form[@id='quote-form']]")->length);
        $this->assertSame(1, $xpath->query("//form[@id='quote-form']//input[@name='_token']")->length);
        $this->assertGreaterThanOrEqual(1, $xpath->query("//button[@type='submit' and @form='quote-form']")->length);
    }

    public function test_edit_workspace_keeps_product_container_and_existing_items_inside_canonical_quote_form(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('form-owner-edit');
        $quote = $this->createEditableQuote($fixture);

        $response = $this->actingAs($fixture['user'], 'web')
            ->withServerVariables(['HTTP_HOST' => parse_url($this->tenantUrl($fixture['tenant'], '/'), PHP_URL_HOST)])
            ->get(route('admin.promotion-quotes.edit', $quote));

        $response->assertOk();

        $xpath = $this->parseHtml($response->getContent());
        $this->assertSame(1, $xpath->query("//form[@id='quote-form' and @data-promotion-quote-form]")->length);
        $this->assertSame(1, $xpath->query("//*[@id='product-items-container'][ancestor::form[@id='quote-form']]")->length);
        $this->assertGreaterThanOrEqual(1, $xpath->query("//button[@type='submit' and @form='quote-form']")->length);
    }

    private function createEditableQuote(array $fixture): Order
    {
        $quote = Order::query()->create([
            'tenant_account_id' => $fixture['tenant']->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-FORM-' . strtoupper(substr(uniqid(), -5)),
            'customer_company_id' => $fixture['customer']->id,
            'status' => 'draft',
            'workflow_status' => 'quote',
            'quote_date' => now()->toDateString(),
            'invoice_status' => 'fis',
            'currency' => 'USD',
            'subtotal' => 164.12,
            'vat_total' => 0,
            'grand_total' => 164.12,
            'product_total' => 164.12,
            'print_total' => 0,
            'created_by' => $fixture['user']->id,
        ]);

        OrderItem::query()->create([
            'tenant_account_id' => $fixture['tenant']->id,
            'order_id' => $quote->id,
            'item_type' => 'product',
            'product_source' => 'tenant_catalog',
            'product_name' => 'Exact Variant',
            'product_code' => 'PZ-CH60SY',
            'quantity' => 1,
            'unit' => 'Adet',
            'list_price' => 164.12,
            'discount_rate' => 0,
            'unit_price' => 164.12,
            'line_total' => 164.12,
            'has_print' => false,
            'print_total' => 0,
            'manual_unit_price' => false,
            'status' => 'draft',
            'catalog_source' => 'tenant_catalog',
            'tenant_catalog_product_id' => $fixture['product']->id,
            'tenant_catalog_product_variant_id' => $fixture['variant']->id,
            'standard_product_id' => $fixture['product']->standard_product_id,
            'standard_product_variant_id' => $fixture['variant']->standard_product_variant_id,
            'product_snapshot' => [
                'tenant_catalog_product_id' => $fixture['product']->id,
                'tenant_catalog_product_variant_id' => $fixture['variant']->id,
                'standard_product_id' => $fixture['product']->standard_product_id,
                'standard_product_variant_id' => $fixture['variant']->standard_product_variant_id,
                'product_name' => 'Exact Variant',
                'product_code' => 'PZ-CH60SY',
            ],
            'price_snapshot' => [
                'list_price' => 164.12,
                'display_price' => 164.12,
                'source_price' => 3.5,
                'source_currency' => 'USD',
                'currency_snapshot' => [
                    'applied_rate' => 46.8914,
                ],
            ],
            'stock_snapshot' => [
                'visible_stock_quantity' => 6500,
            ],
        ]);

        return $quote;
    }

    private function parseHtml(string $html): DOMXPath
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
