<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteFreshnessFixtures;
use Tests\TestCase;

class PromotionQuoteCatalogItemAttributionTest extends TestCase
{
    use BuildsQuoteFreshnessFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_create_workspace_contains_canonical_catalog_item_hidden_fields_and_collect_items_keeps_product_snapshot(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('attribution-contract');

        $response = $this->actingAs($fixture['user'], 'web')
            ->withServerVariables(['HTTP_HOST' => parse_url($this->tenantUrl($fixture['tenant'], '/'), PHP_URL_HOST)])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk();
        $response->assertSee('name="items[${item._index}][tenant_catalog_product_id]"', false);
        $response->assertSee('name="items[${item._index}][tenant_catalog_product_variant_id]"', false);
        $response->assertSee('name="items[${item._index}][selected_catalog_identity]"', false);
        $response->assertSee('name="items[${item._index}][product_snapshot]"', false);
        $response->assertSee('name="items[${item._index}][price_snapshot]"', false);
        $response->assertSee('product_snapshot: productSnapshot,', false);

        $xpath = $this->parseHtml($response->getContent());
        $this->assertSame(1, $xpath->query("//form[@id='quote-form' and @data-promotion-quote-form]")->length);
    }

    public function test_store_request_accepts_flat_catalog_item_payload_and_persists_product_without_variant(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('flat-catalog', [
            'variant_currency' => 'TRY',
            'product_display_price' => 134.00,
            'variant_display_price' => 134.00,
            'standard_product_price' => 134.00,
            'standard_variant_price' => 134.00,
            'source_price' => 134.00,
            'base_price' => 134.00,
            'applied_rate' => 1.0,
        ]);

        $payload = $this->buildQuoteStorePayload($fixture, [
            'product_name' => $fixture['product']->display_name,
            'product_code' => $fixture['product']->display_code,
            'tenant_catalog_product_variant_id' => '',
            'standard_product_variant_id' => '',
            'selected_catalog_identity' => [
                'catalog_source' => 'tenant_catalog',
                'tenant_catalog_product_id' => $fixture['product']->id,
                'tenant_catalog_product_variant_id' => null,
                'standard_product_id' => $fixture['product']->standard_product_id,
                'standard_product_variant_id' => null,
                'product_code' => $fixture['product']->display_code,
                'product_name' => $fixture['product']->display_name,
                'is_warning_sellable' => false,
            ],
            'product_snapshot' => [
                'tenant_catalog_product_id' => $fixture['product']->id,
                'tenant_catalog_product_variant_id' => null,
                'standard_product_id' => $fixture['product']->standard_product_id,
                'standard_product_variant_id' => null,
                'product_code' => $fixture['product']->display_code,
                'product_name' => $fixture['product']->display_name,
            ],
            'price_snapshot' => [
                'list_price' => 134.00,
                'display_price' => 134.00,
                'currency' => 'TRY',
                'source_price' => 134.00,
                'source_currency' => 'TRY',
                'quote_price_value' => 134.00,
                'quote_currency' => 'TRY',
                'quote_price_status' => 'not_required',
                'quote_price_snapshot' => [
                    'document_currency' => 'TRY',
                    'suggested_sales_unit_price_document' => 134.00,
                    'actual_sales_unit_price_document' => 134.00,
                    'manual_sales_price_override' => false,
                    'document_conversion_status' => 'not_required',
                    'applied_rate' => 1.0,
                    'rate_date' => '2026-07-10',
                    'rate_source' => 'tcmb',
                    'rate_type' => 'identity',
                    'source_price' => 134.00,
                    'source_currency' => 'TRY',
                ],
                'vat_rate' => 20,
            ],
            'quantity' => '1',
            'line_total' => '134.00',
        ]);

        $response = $this->actingAs($fixture['user'], 'web')
            ->from(route('admin.promotion-quotes.create'))
            ->withServerVariables(['HTTP_HOST' => parse_url($this->tenantUrl($fixture['tenant'], '/'), PHP_URL_HOST)])
            ->post(route('admin.promotion-quotes.store'), $payload);

        $response->assertSessionDoesntHaveErrors(['items']);

        $item = OrderItem::query()->latest('id')->firstOrFail();
        $this->assertSame($fixture['product']->id, (int) $item->tenant_catalog_product_id);
        $this->assertNull($item->tenant_catalog_product_variant_id);
        $this->assertSame($fixture['product']->standard_product_id, (int) $item->standard_product_id);
        $this->assertNull($item->standard_product_variant_id);
        $this->assertSame($fixture['product']->display_name, data_get($item->product_snapshot, 'product_name'));
    }

    public function test_store_request_accepts_local_product_catalog_source_without_losing_canonical_ids(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('local-product');
        $payload = $this->buildQuoteStorePayload($fixture, [
            'catalog_source' => 'local_product',
            'selected_catalog_identity' => [
                'catalog_source' => 'local_product',
                'tenant_catalog_product_id' => $fixture['product']->id,
                'tenant_catalog_product_variant_id' => $fixture['variant']->id,
                'standard_product_id' => $fixture['product']->standard_product_id,
                'standard_product_variant_id' => $fixture['variant']->standard_product_variant_id,
                'product_code' => $fixture['variant']->variant_code,
                'product_name' => $fixture['variant']->display_name,
                'is_warning_sellable' => false,
            ],
        ]);

        $response = $this->actingAs($fixture['user'], 'web')
            ->withServerVariables(['HTTP_HOST' => parse_url($this->tenantUrl($fixture['tenant'], '/'), PHP_URL_HOST)])
            ->post(route('admin.promotion-quotes.store'), $payload);

        $response->assertSessionDoesntHaveErrors(['items']);

        $item = OrderItem::query()->latest('id')->firstOrFail();
        $this->assertSame('local_product', $item->catalog_source);
        $this->assertSame($fixture['product']->id, (int) $item->tenant_catalog_product_id);
        $this->assertSame($fixture['variant']->id, (int) $item->tenant_catalog_product_variant_id);
    }

    public function test_store_request_rejects_cross_tenant_catalog_item_tampering(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('alpha');
        $foreign = $this->createQuoteFreshnessFixture('bravo');
        $payload = $this->buildQuoteStorePayload($fixture, [
            'tenant_catalog_product_id' => $foreign['product']->id,
            'tenant_catalog_product_variant_id' => $foreign['variant']->id,
            'standard_product_id' => $foreign['product']->standard_product_id,
            'standard_product_variant_id' => $foreign['variant']->standard_product_variant_id,
            'selected_catalog_identity' => [
                'catalog_source' => 'tenant_catalog',
                'tenant_catalog_product_id' => $foreign['product']->id,
                'tenant_catalog_product_variant_id' => $foreign['variant']->id,
                'standard_product_id' => $foreign['product']->standard_product_id,
                'standard_product_variant_id' => $foreign['variant']->standard_product_variant_id,
                'product_code' => $foreign['variant']->variant_code,
                'product_name' => $foreign['variant']->display_name,
                'is_warning_sellable' => false,
            ],
            'product_snapshot' => [
                'tenant_catalog_product_id' => $foreign['product']->id,
                'tenant_catalog_product_variant_id' => $foreign['variant']->id,
                'standard_product_id' => $foreign['product']->standard_product_id,
                'standard_product_variant_id' => $foreign['variant']->standard_product_variant_id,
                'product_code' => $foreign['variant']->variant_code,
                'product_name' => $foreign['variant']->display_name,
            ],
        ]);

        $response = $this->actingAs($fixture['user'], 'web')
            ->withServerVariables(['HTTP_HOST' => parse_url($this->tenantUrl($fixture['tenant'], '/'), PHP_URL_HOST)])
            ->post(route('admin.promotion-quotes.store'), $payload);

        $response->assertRedirect();
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_store_request_rejects_variant_parent_mismatch(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('charlie');

        $otherProduct = TenantCatalogProduct::query()->create([
            'tenant_account_id' => $fixture['tenant']->id,
            'standard_product_id' => $fixture['product']->standard_product_id,
            'tenant_sku' => 'TEN-MISMATCH-' . uniqid(),
            'name' => 'Mismatch Parent Product',
            'product_code' => 'MIS-P-' . strtoupper(substr(uniqid(), -6)),
            'product_name' => 'Mismatch Parent Product',
            'slug' => 'mismatch-parent-product-' . uniqid(),
            'standard_category_id' => $fixture['product']->standard_category_id,
            'product_family' => 'promotion',
            'display_price' => 134.00,
            'sale_price' => 134.00,
            'currency' => 'TRY',
            'total_stock_quantity' => 200,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 200,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => $fixture['product']->source_summary,
            'visible_in_catalog' => true,
            'visible_in_quote' => true,
            'catalog_source' => 'supplier_projection',
            'catalog_status' => 'ready',
            'last_synced_at' => now(),
            'meta' => $fixture['product']->meta,
            'is_active' => true,
            'stock_quantity' => 200,
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => [],
        ]);

        $otherVariant = TenantCatalogProductVariant::query()->create([
            'tenant_account_id' => $fixture['tenant']->id,
            'tenant_catalog_product_id' => $otherProduct->id,
            'standard_product_variant_id' => $fixture['variant']->standard_product_variant_id,
            'variant_code' => 'MIS-V-' . strtoupper(substr(uniqid(), -6)),
            'variant_name' => 'Mismatch Variant',
            'variant_color' => 'Gri',
            'display_price' => 134.00,
            'currency' => 'TRY',
            'stock_quantity' => 100,
            'local_stock_quantity' => 0,
            'supplier_stock_quantity' => 100,
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => true,
            'is_active' => true,
            'source_summary' => $fixture['variant']->source_summary,
            'meta' => $fixture['variant']->meta,
        ]);

        $payload = $this->buildQuoteStorePayload($fixture, [
            'tenant_catalog_product_variant_id' => $otherVariant->id,
            'standard_product_variant_id' => $otherVariant->standard_product_variant_id,
            'selected_catalog_identity' => [
                'catalog_source' => 'tenant_catalog',
                'tenant_catalog_product_id' => $fixture['product']->id,
                'tenant_catalog_product_variant_id' => $otherVariant->id,
                'standard_product_id' => $fixture['product']->standard_product_id,
                'standard_product_variant_id' => $otherVariant->standard_product_variant_id,
                'product_code' => $otherVariant->variant_code,
                'product_name' => $otherVariant->display_name,
                'is_warning_sellable' => false,
            ],
            'product_snapshot' => [
                'tenant_catalog_product_id' => $fixture['product']->id,
                'tenant_catalog_product_variant_id' => $otherVariant->id,
                'standard_product_id' => $fixture['product']->standard_product_id,
                'standard_product_variant_id' => $otherVariant->standard_product_variant_id,
                'product_code' => $otherVariant->variant_code,
                'product_name' => $otherVariant->display_name,
            ],
        ]);

        $response = $this->actingAs($fixture['user'], 'web')
            ->from(route('admin.promotion-quotes.create'))
            ->withServerVariables(['HTTP_HOST' => parse_url($this->tenantUrl($fixture['tenant'], '/'), PHP_URL_HOST)])
            ->post(route('admin.promotion-quotes.store'), $payload);

        $response->assertRedirect(route('admin.promotion-quotes.create'));
        $response->assertSessionHasErrors([
            'items.0.tenant_catalog_product_variant_id' => 'Seçilen varyasyon ürün ile eşleşmiyor. Lütfen satırı yeniden seçin.',
        ]);
    }

    public function test_store_request_rejects_quote_hidden_or_access_closed_catalog_items(): void
    {
        $hiddenFixture = $this->createQuoteFreshnessFixture('qhidden', [
            'visible_in_quote' => false,
            'variant_quote_visible' => false,
        ]);
        $hiddenResponse = $this->actingAs($hiddenFixture['user'], 'web')
            ->from(route('admin.promotion-quotes.create'))
            ->withServerVariables(['HTTP_HOST' => parse_url($this->tenantUrl($hiddenFixture['tenant'], '/'), PHP_URL_HOST)])
            ->post(route('admin.promotion-quotes.store'), $this->buildQuoteStorePayload($hiddenFixture));

        $hiddenResponse->assertRedirect(route('admin.promotion-quotes.create'));
        $hiddenResponse->assertSessionHasErrors([
            'items.0.product_snapshot' => 'Bu ürün artık teklif için kullanılamıyor. Lütfen yeniden ürün seçin.',
        ]);

        $accessClosedFixture = $this->createQuoteFreshnessFixture('aclosed');
        TenantSupplierAccess::query()
            ->where('tenant_account_id', $accessClosedFixture['tenant']->id)
            ->where('supplier_id', $accessClosedFixture['supplier']->id)
            ->update(['can_use_in_quotes' => false]);

        $closedResponse = $this->actingAs($accessClosedFixture['user'], 'web')
            ->from(route('admin.promotion-quotes.create'))
            ->withServerVariables(['HTTP_HOST' => parse_url($this->tenantUrl($accessClosedFixture['tenant'], '/'), PHP_URL_HOST)])
            ->post(route('admin.promotion-quotes.store'), $this->buildQuoteStorePayload($accessClosedFixture));

        $closedResponse->assertRedirect(route('admin.promotion-quotes.create'));
        $closedResponse->assertSessionHasErrors([
            'items.0.product_snapshot' => 'Bu ürün artık teklif için kullanılamıyor. Lütfen yeniden ürün seçin.',
        ]);
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
