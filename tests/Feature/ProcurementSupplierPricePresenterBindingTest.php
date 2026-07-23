<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\SupplierProductRaw;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementSupplierPricePresenterBindingTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';
    private const FX_RATE_DATE = '2026-07-14';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_visible_purchase_unit_input_is_populated_from_effective_final_unit_when_manual_override_is_false(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-BINDING');
        $this->createRate('USD', '46.99660000', self::FX_RATE_DATE);

        $raw = SupplierProductRaw::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'SRC-' . uniqid(),
            'source_name' => 'Binding Raw',
            'supplier_product_code' => 'PZ-CH60SY',
            'product_name' => 'PZ CH60SY Etiket',
            'purchase_price' => '3.5000',
            'currency' => 'USD',
            'sync_status' => 'processed',
        ]);

        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-BIND-001', [
            'quantity' => 10,
            'product_snapshot' => [
                'product_name' => 'PZ CH60SY Etiket',
                'product_code' => 'PZ-CH60SY',
                'supplier_name' => $supplier->name,
                'supplier_product_raw_id' => $raw->id,
            ],
        ]);
        $this->pinProcurementQuoteDate($procurement);

        $request = $this->createSupplierRequest($procurement);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $request));

        $response->assertOk();
        $response->assertSee('value="164.49"', false);
        $response->assertSee('data-calculated-unit-value="164.488100"', false);
        $response->assertSee('Hesaplananı kullan');
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
