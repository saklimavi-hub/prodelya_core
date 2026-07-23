<?php

namespace Tests\Feature;

use App\Models\Order;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteFreshnessFixtures;
use Tests\TestCase;

class PromotionQuoteNoNestedFormRegressionTest extends TestCase
{
    use BuildsQuoteFreshnessFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_create_workspace_contains_no_nested_forms_inside_canonical_form(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('no-nested-create');

        $response = $this->actingAs($fixture['user'], 'web')
            ->withServerVariables(['HTTP_HOST' => parse_url($this->tenantUrl($fixture['tenant'], '/'), PHP_URL_HOST)])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();

        $xpath = $this->parseHtml($response->getContent());
        $form = $xpath->query("//form[@id='quote-form']")->item(0);

        $this->assertNotNull($form);
        $this->assertSame(0, $xpath->query('.//form', $form)->length);
    }

    public function test_edit_workspace_keeps_currency_side_forms_outside_canonical_form(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('no-nested-edit');
        $quote = Order::query()->create([
            'tenant_account_id' => $fixture['tenant']->id,
            'order_family' => 'promotion',
            'order_mode' => 'product_sale_print',
            'document_type' => 'quote',
            'document_number' => 'TK-NEST-' . strtoupper(substr(uniqid(), -5)),
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

        $response = $this->actingAs($fixture['user'], 'web')
            ->withServerVariables(['HTTP_HOST' => parse_url($this->tenantUrl($fixture['tenant'], '/'), PHP_URL_HOST)])
            ->get(route('admin.promotion-quotes.edit', $quote));

        $response->assertOk();

        $xpath = $this->parseHtml($response->getContent());
        $form = $xpath->query("//form[@id='quote-form']")->item(0);

        $this->assertNotNull($form);
        $this->assertSame(0, $xpath->query('.//form', $form)->length);
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
