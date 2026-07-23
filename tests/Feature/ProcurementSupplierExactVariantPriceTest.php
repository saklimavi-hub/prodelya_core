<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\SupplierProductRaw;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementSupplierExactVariantPriceTest extends TestCase
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

    public function test_procurement_uses_exact_variant_price_instead_of_sibling_variant_source(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-EXACT');
        $this->createRate('USD', '46.99660000', self::FX_RATE_DATE);

        SupplierProductRaw::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'SRC-' . uniqid(),
            'source_name' => 'Sibling Raw',
            'supplier_product_code' => 'PZ-CH60SX',
            'product_name' => 'Sibling Variant',
            'purchase_price' => '9.9000',
            'currency' => 'USD',
            'sync_status' => 'processed',
        ]);

        $exactRaw = SupplierProductRaw::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'SRC-' . uniqid(),
            'source_name' => 'Exact Raw',
            'supplier_product_code' => 'PZ-CH60SY',
            'product_name' => 'Exact Variant',
            'purchase_price' => '3.5000',
            'currency' => 'USD',
            'sync_status' => 'processed',
        ]);

        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-EXACT-001', [
            'quantity' => 10,
            'product_snapshot' => [
                'product_name' => 'PZ CH60SY Etiket',
                'product_code' => 'PZ-CH60SY',
                'supplier_name' => $supplier->name,
                'supplier_product_raw_id' => $exactRaw->id,
            ],
        ]);
        $this->pinProcurementQuoteDate($procurement);

        $request = $this->createSupplierRequest($procurement);
        $item = $request->items()->firstOrFail();

        $this->assertSame(3.5, (float) $item->purchase_source_amount);
        $this->assertSame('PZ-CH60SY', data_get($item->purchase_price_snapshot, 'supplier_product_code'));
        $this->assertNotSame(9.9, (float) $item->purchase_source_amount);
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
