<?php

namespace Tests\Feature;

use App\Services\ProductDataHub\ProductHubFreshnessDiagnosticService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsQuoteFreshnessFixtures;
use Tests\TestCase;

class ProductHubQuoteFreshnessDiagnosticTest extends TestCase
{
    use BuildsQuoteFreshnessFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_try_flat_and_exact_variant_use_current_exact_chain_without_fallback(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('try-exact', [
            'product_code' => 'EL-KOD-35',
            'variant_code' => 'EL-KOD-35-V1',
            'product_currency' => 'TRY',
            'variant_currency' => 'TRY',
            'product_display_price' => 134.0,
            'variant_display_price' => 134.0,
            'standard_product_price' => 134.0,
            'standard_variant_price' => 134.0,
            'product_total_stock' => 500,
            'variant_total_stock' => 275,
            'variant_supplier_stock' => 275,
            'standard_product_stock' => 500,
            'standard_variant_stock' => 275,
            'standard_updated_at' => now()->subMinute(),
            'projection_updated_at' => now()->addMinute(),
        ]);

        $service = app(ProductHubFreshnessDiagnosticService::class);
        $flat = $service->buildQuoteFreshnessPayload($fixture['product']);
        $exact = $service->buildQuoteFreshnessPayload($fixture['product'], $fixture['variant']);

        $this->assertSame('fresh', $flat['status']);
        $this->assertSame('fresh', $exact['status']);
        $this->assertFalse($exact['stale_price']);
        $this->assertFalse($exact['stale_stock']);
        $this->assertFalse($exact['blocking']);
    }

    public function test_projection_lag_sets_projection_outdated_without_blocking_when_values_match(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('projection-lag', [
            'standard_variant_price' => 164.12,
            'variant_display_price' => 164.12,
            'standard_variant_stock' => 6500,
            'variant_total_stock' => 6500,
            'variant_supplier_stock' => 6500,
            'standard_updated_at' => now()->addSeconds(8),
            'projection_updated_at' => now(),
        ]);

        $payload = app(ProductHubFreshnessDiagnosticService::class)
            ->buildQuoteFreshnessPayload($fixture['product'], $fixture['variant']);

        $this->assertTrue($payload['projection_outdated']);
        $this->assertSame('projection_lag', $payload['status']);
        $this->assertFalse($payload['stale_price']);
        $this->assertFalse($payload['stale_stock']);
        $this->assertFalse($payload['blocking']);
    }

    public function test_stale_price_blocks_even_on_exact_variant_chain(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('stale-price', [
            'standard_variant_price' => 182.45,
            'variant_display_price' => 164.12,
        ]);

        $payload = app(ProductHubFreshnessDiagnosticService::class)
            ->buildQuoteFreshnessPayload($fixture['product'], $fixture['variant']);

        $this->assertSame('stale_price', $payload['status']);
        $this->assertTrue($payload['stale_price']);
        $this->assertFalse($payload['stale_stock']);
        $this->assertTrue($payload['blocking']);
    }

    public function test_stale_stock_warns_without_blocking(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('stale-stock', [
            'standard_variant_stock' => 6100,
            'variant_total_stock' => 6500,
            'variant_supplier_stock' => 6500,
        ]);

        $payload = app(ProductHubFreshnessDiagnosticService::class)
            ->buildQuoteFreshnessPayload($fixture['product'], $fixture['variant']);

        $this->assertSame('stale_stock', $payload['status']);
        $this->assertFalse($payload['stale_price']);
        $this->assertTrue($payload['stale_stock']);
        $this->assertFalse($payload['blocking']);
    }
    public function test_small_rate_delta_with_same_rounded_try_is_projection_warning_not_stale_price(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('rate-warning', [
            'product_currency' => 'USD',
            'variant_currency' => 'USD',
            'source_price' => 3.5,
            'standard_variant_price' => 164.12445,
            'variant_display_price' => 164.11990,
            'product_display_price' => 164.11990,
            'standard_updated_at' => now()->addSeconds(8),
            'projection_updated_at' => now(),
        ]);

        $standardMeta = $fixture['standardVariant']->meta ?? [];
        data_set($standardMeta, 'price_snapshot.currency_snapshot.applied_rate', 46.8927);
        $fixture['standardVariant']->forceFill(['meta' => $standardMeta])->save();

        $projectionMeta = $fixture['variant']->meta ?? [];
        data_set($projectionMeta, 'price_snapshot.currency_snapshot.applied_rate', 46.8914);
        $fixture['variant']->forceFill(['meta' => $projectionMeta])->save();

        $payload = app(ProductHubFreshnessDiagnosticService::class)
            ->buildQuoteFreshnessPayload($fixture['product']->fresh(), $fixture['variant']->fresh());

        $this->assertSame('projection_lag', $payload['status']);
        $this->assertTrue($payload['projection_outdated']);
        $this->assertFalse($payload['stale_price']);
        $this->assertFalse($payload['blocking']);
        $this->assertContains('rate_changed_since_projection', $payload['warning_codes']);
    }

    public function test_source_amount_difference_remains_stale_even_if_rounded_try_matches(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('source-amount-diff', [
            'product_currency' => 'USD',
            'variant_currency' => 'USD',
            'source_price' => 3.5,
            'standard_variant_price' => 164.12,
            'variant_display_price' => 164.12,
            'product_display_price' => 164.12,
        ]);

        $standardMeta = $fixture['standardVariant']->meta ?? [];
        data_set($standardMeta, 'price_snapshot.source_price', 3.6);
        $fixture['standardVariant']->forceFill(['meta' => $standardMeta])->save();

        $payload = app(ProductHubFreshnessDiagnosticService::class)
            ->buildQuoteFreshnessPayload($fixture['product']->fresh(), $fixture['variant']->fresh());

        $this->assertTrue($payload['stale_price']);
        $this->assertTrue($payload['blocking']);
    }

    public function test_source_currency_difference_remains_stale_even_if_try_amount_matches(): void
    {
        $fixture = $this->createQuoteFreshnessFixture('source-currency-diff', [
            'product_currency' => 'USD',
            'variant_currency' => 'USD',
            'source_price' => 3.5,
            'standard_variant_price' => 164.12,
            'variant_display_price' => 164.12,
            'product_display_price' => 164.12,
        ]);

        $standardMeta = $fixture['standardVariant']->meta ?? [];
        data_set($standardMeta, 'price_snapshot.source_currency', 'EUR');
        $fixture['standardVariant']->forceFill(['meta' => $standardMeta])->save();

        $payload = app(ProductHubFreshnessDiagnosticService::class)
            ->buildQuoteFreshnessPayload($fixture['product']->fresh(), $fixture['variant']->fresh());

        $this->assertTrue($payload['stale_price']);
        $this->assertTrue($payload['blocking']);
    }
}
