<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteFreshnessFixtures;
use Tests\TestCase;

class PromotionQuoteValidationRerenderItemsTest extends TestCase
{
    use BuildsQuoteFreshnessFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_validation_failure_on_another_field_preserves_item_payload_on_rerender(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('rerender-items');
        $payload = $this->buildQuoteStorePayload($fixture, [
            'quantity' => '1',
            'line_total' => '164.12',
        ]);
        $payload['invoice_status'] = 'broken';

        $response = $this->actingAs($fixture['user'], 'web')
            ->followingRedirects()
            ->from(route('admin.promotion-quotes.create'))
            ->withServerVariables(['HTTP_HOST' => parse_url($this->tenantUrl($fixture['tenant'], '/'), PHP_URL_HOST)])
            ->post(route('admin.promotion-quotes.store'), $payload);

        $response->assertOk();
        $response->assertSee('data-promotion-quote-form', false);
        $response->assertSee('product-items-container', false);
        $response->assertSee('"tenant_catalog_product_variant_id":' . $fixture['variant']->id, false);
        $response->assertSee('"product_code":"PZ-CH60SY"', false);
        $response->assertDontSee('The items field is required.');
    }

    public function test_missing_items_message_is_turkish(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('rerender-items-empty');
        $payload = $this->buildQuoteStorePayload($fixture);
        $payload['items'] = [];

        $response = $this->actingAs($fixture['user'], 'web')
            ->from(route('admin.promotion-quotes.create'))
            ->withServerVariables(['HTTP_HOST' => parse_url($this->tenantUrl($fixture['tenant'], '/'), PHP_URL_HOST)])
            ->post(route('admin.promotion-quotes.store'), $payload);

        $response->assertRedirect(route('admin.promotion-quotes.create'));
        $response->assertSessionHasErrors([
            'items' => 'En az bir ürün kalemi ekleyin.',
        ]);
    }
}
