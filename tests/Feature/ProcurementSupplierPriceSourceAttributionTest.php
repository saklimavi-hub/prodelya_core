<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\SupplierProductRaw;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementSupplierPriceSourceAttributionTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    private const FX_RATE_DATE = '2026-07-14';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_exact_variant_raw_source_is_used_as_canonical_procurement_truth_without_sales_fallback(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-ATTR');
        $this->createRate('USD', '46.99660000', self::FX_RATE_DATE);

        $raw = SupplierProductRaw::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'SRC-' . uniqid(),
            'source_name' => 'CH60SY Raw',
            'supplier_product_code' => 'PZ-CH60SY',
            'product_name' => 'PZ CH60SY Etiket',
            'purchase_price' => '3.5000',
            'currency' => 'USD',
            'sync_status' => 'processed',
        ]);

        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-ATTR-001', [
            'quantity' => 10,
            'list_price' => 999,
            'unit_price' => 888,
            'line_total' => 8880,
            'price_snapshot' => [
                'unit_price' => 888,
                'line_total' => 8880,
                'vat_total' => 1598.4,
            ],
            'product_snapshot' => [
                'product_name' => 'PZ CH60SY Etiket',
                'product_code' => 'PZ-CH60SY',
                'supplier_name' => $supplier->name,
                'supplier_product_raw_id' => $raw->id,
            ],
        ]);
        $this->pinProcurementQuoteDate($procurement);

        $request = $this->createSupplierRequest($procurement);
        $item = $request->items()->firstOrFail();

        $this->assertSame(3.5, (float) $item->purchase_source_amount);
        $this->assertSame('USD', $item->purchase_source_currency);
        $this->assertSame(46.9966, round((float) $item->purchase_fx_rate, 4));
        $this->assertSame(164.4881, round((float) $item->purchase_list_price_try, 4));
        $this->assertSame(164.49, (float) $item->purchase_unit_price);
        $this->assertSame('PZ-CH60SY', data_get($item->purchase_price_snapshot, 'supplier_product_code'));
        $this->assertSame('resolved', data_get($item->purchase_price_snapshot, 'resolution_status'));
    }

    private function pinProcurementQuoteDate($procurement): void
    {
        $procurement->order?->forceFill([
            'quote_date' => self::FX_RATE_DATE,
        ])->save();

        $procurement->unsetRelation('order');
    }



    private function createRate(string $sourceCurrency, string $rate, string $date): void
    {
        ExchangeRate::query()
            ->where('provider', 'tcmb')
            ->where('rate_type', 'forex_selling')
            ->where('source_currency', $sourceCurrency)
            ->where('target_currency', 'TRY')
            ->whereDate('rate_date', $date)
            ->delete();

        ExchangeRate::query()->create([
            'provider' => 'tcmb',
            'rate_type' => 'forex_selling',
            'source_currency' => $sourceCurrency,
            'target_currency' => 'TRY',
            'rate_date' => $date,
            'source_unit' => 1,
            'rate' => $rate,
            'fetched_at' => now(),
            'payload_hash' => (string) Str::uuid(),
            'meta_json' => [],
        ]);
    }
}
