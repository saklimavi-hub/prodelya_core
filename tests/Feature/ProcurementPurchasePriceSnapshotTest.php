<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\SupplierProductRaw;
use App\Services\SupplierProcurementRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementPurchasePriceSnapshotTest extends TestCase
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

    public function test_usd_source_persists_canonical_snapshot_and_completed_manual_override_keeps_original_fx_truth(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-USD');
        $this->createRate('USD', '46.99610000', self::FX_RATE_DATE);

        $raw = SupplierProductRaw::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'SRC-' . uniqid(),
            'source_name' => 'Fixture Kaynak Ürün',
            'supplier_product_code' => 'RAW-PROC-USD-001',
            'product_name' => 'USD Tedarik Ürün',
            'purchase_price' => '7.7500',
            'currency' => 'USD',
            'sync_status' => 'processed',
        ]);

        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-USD-001', [
            'product_snapshot' => [
                'product_name' => 'USD Tedarik Ürün',
                'product_code' => 'PROC-USD-001',
                'supplier_product_raw_id' => $raw->id,
            ],
        ]);
        $this->pinProcurementQuoteDate($procurement);

        $request = app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $supplier->id,
            [$procurement->id],
            $this->adminUser
        );

        $item = $request->items()->firstOrFail();

        $this->assertSame('USD', $item->purchase_source_currency);
        $this->assertSame(7.75, (float) $item->purchase_source_amount);
        $this->assertSame(46.9961, (float) $item->purchase_fx_rate);
        $this->assertSame(364.219775, round((float) $item->purchase_list_price_try, 6));
        $this->assertSame(364.22, (float) $item->purchase_list_price);
        $this->assertSame('resolved', data_get($item->purchase_price_snapshot, 'resolution_status'));

        $raw->forceFill(['purchase_price' => '8.1000'])->save();
        $this->createRate('USD', '47.50000000', self::FX_RATE_DATE);

        app(SupplierProcurementRequestService::class)->updateRequestItems(
            $request->fresh('items.procurement'),
            [[
                'id' => $item->id,
                'requested_quantity' => '60',
                'purchase_list_price' => '364,22',
                'discount_rate' => '55,00',
                'purchase_unit_price' => '170,000000',
                'note' => 'manuel override',
            ]],
            $this->adminUser,
            'Snapshot kilit testi'
        );

        $item->refresh();

        $this->assertSame(7.75, (float) $item->purchase_source_amount);
        $this->assertSame('USD', $item->purchase_source_currency);
        $this->assertSame(46.9961, (float) $item->purchase_fx_rate);
        $this->assertSame(163.898899, round((float) $item->purchase_calculated_unit_price, 6));
        $this->assertSame(170.0, (float) $item->purchase_manual_unit_price);
        $this->assertTrue((bool) $item->purchase_manual_override);
        $this->assertSame(170.0, (float) $item->purchase_unit_price);
        $this->assertSame('resolved', data_get($item->purchase_price_snapshot, 'resolution_status'));
    }

    public function test_eur_source_uses_procurement_fx_snapshot_and_try_settlement(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-EUR');
        $this->createRate('EUR', '51.25000000', self::FX_RATE_DATE);

        $raw = SupplierProductRaw::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'SRC-' . uniqid(),
            'source_name' => 'Fixture Kaynak Ürün',
            'supplier_product_code' => 'RAW-PROC-EUR-001',
            'product_name' => 'EUR Tedarik Ürün',
            'purchase_price' => '5.5000',
            'currency' => 'EUR',
            'sync_status' => 'processed',
        ]);

        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-EUR-001', [
            'product_snapshot' => [
                'product_name' => 'EUR Tedarik Ürün',
                'product_code' => 'PROC-EUR-001',
                'supplier_product_raw_id' => $raw->id,
            ],
        ]);
        $this->pinProcurementQuoteDate($procurement);

        $request = app(SupplierProcurementRequestService::class)->createDraftForSupplier(
            $this->tenant,
            $supplier->id,
            [$procurement->id],
            $this->adminUser
        );

        $item = $request->items()->firstOrFail();

        $this->assertSame('EUR', $item->purchase_source_currency);
        $this->assertSame('TRY', $item->purchase_settlement_currency);
        $this->assertSame(51.25, (float) $item->purchase_fx_rate);
        $this->assertSame(281.875000, round((float) $item->purchase_list_price_try, 6));
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
