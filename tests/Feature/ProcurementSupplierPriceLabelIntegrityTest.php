<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Services\SupplierProcurementRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementSupplierPriceLabelIntegrityTest extends TestCase
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

    public function test_try_source_shows_original_amount_without_identity_rate_or_sales_wording(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-LABEL-TRY');

        $raw = SupplierProductRaw::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'SRC-' . uniqid(),
            'source_name' => 'TRY Raw',
            'supplier_product_code' => 'PZ-TRY-001',
            'product_name' => 'TRY Etiket',
            'purchase_price' => '9.2000',
            'currency' => 'TRY',
            'sync_status' => 'processed',
        ]);

        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-LABEL-TRY-001', [
            'product_snapshot' => [
                'product_name' => 'TRY Etiket',
                'product_code' => 'PZ-TRY-001',
                'supplier_name' => $supplier->name,
                'supplier_product_raw_id' => $raw->id,
            ],
        ]);

        $request = $this->createSupplierRequest($procurement);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $request));

        $response->assertOk();
        $response->assertSee('Tedarikçi liste:');
        $response->assertSee('9,20 TL');
        $response->assertDontSee('Kur: 1 TRY');
        $response->assertDontSee('Satış Liste');
        $response->assertDontSee('Satış Toplam');
    }

    public function test_usd_source_shows_original_amount_rate_and_try_equivalent_without_sales_wording(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-LABEL-USD');
        $this->createRate('USD', '46.99660000', self::FX_RATE_DATE);

        $raw = SupplierProductRaw::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'SRC-' . uniqid(),
            'source_name' => 'USD Raw',
            'supplier_product_code' => 'PZ-CH60SY',
            'product_name' => 'USD Etiket',
            'purchase_price' => '3.5000',
            'currency' => 'USD',
            'sync_status' => 'processed',
        ]);

        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-LABEL-USD-001', [
            'quantity' => 10,
            'product_snapshot' => [
                'product_name' => 'USD Etiket',
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
        $response->assertSee('3,50 USD');
        $response->assertSee('TL karşılığı: 164,49 TL');
        $response->assertSee('Kur: 1 USD = 46,9966 TL');
        $response->assertDontSee('Satış Liste');
        $response->assertDontSee('Satış Toplam');
        $response->assertDontSee('0,00 USD');
    }

    public function test_akdeniz_supplier_list_label_shows_gross_list_not_net_or_sales_final(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('PROC-LABEL-AK');
        $source->update(['config' => ['profile_key' => 'AKDENIZ']]);

        $rawPayload = [
            'urunkodu' => '1020',
            'urunattrgr' => '1020',
            'urunattradi' => '1020 Kırmızı',
            'urunadi' => '1020 Kırmızı Metal Tükenmez Rubber Gövde Kalem',
            'pure_prodname' => 'Metal Tükenmez Rubber Gövde Kalem',
            'listefiyati' => '30.50',
            'listefiyatkapali' => '30.50',
            'netfiyat' => '16.78',
            'iskonto' => '0.45',
            'kur' => 'TL',
        ];

        $normalizedPayload = [
            'supplier_product_code' => '1020',
            'supplier_group_code' => '1020',
            'variant_name' => '1020 Kırmızı',
            'product_name' => '1020 Kırmızı Metal Tükenmez Rubber Gövde Kalem',
            'base_product_name' => 'Metal Tükenmez Rubber Gövde Kalem',
            'list_price' => 30.50,
            'closed_list_price' => 30.50,
            'purchase_price' => 16.78,
            'net_price' => 16.78,
            'currency' => 'TL',
            'profile_key' => 'AKDENIZ',
        ];

        $raw = SupplierProductRaw::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'SRC-' . uniqid(),
            'source_name' => 'Akdeniz Raw',
            'supplier_product_code' => '1020',
            'product_name' => '1020 Kırmızı Metal Tükenmez Rubber Gövde Kalem',
            'purchase_price' => '16.7800',
            'currency' => 'TRY',
            'source_price' => '30.50',
            'source_currency' => 'TRY',
            'raw_payload' => $rawPayload,
            'normalized_payload' => $normalizedPayload,
            'sync_status' => 'processed',
        ]);

        $variant = SupplierProductVariantRaw::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'supplier_product_raw_id' => $raw->id,
            'variant_code' => 'AK-1020-KIRMIZI',
            'variant_name' => '1020 Kırmızı',
            'raw_payload' => $rawPayload,
            'normalized_payload' => $normalizedPayload,
            'sync_status' => 'processed',
        ]);

        $procurement = $this->createProcurement($supplier, $source, 'SP-PROC-LABEL-AK-001', [
            'quantity' => 100,
            'product_name' => '1020 Kırmızı Metal Tükenmez Rubber Gövde Kalem',
            'product_code' => 'AK-1020-KIRMIZI',
            'list_price' => 30.50,
            'discount_rate' => 45,
            'unit_price' => 16.78,
            'line_total' => 1677.50,
            'product_snapshot' => [
                'product_name' => '1020 Kırmızı Metal Tükenmez Rubber Gövde Kalem',
                'product_code' => 'AK-1020-KIRMIZI',
                'supplier_name' => $supplier->name,
                'supplier_product_raw_id' => $raw->id,
                'supplier_product_variant_raw_id' => $variant->id,
            ],
            'price_snapshot' => [
                'source_price' => 30.50,
                'source_currency' => 'TRY',
                'list_price' => 30.50,
                'discount_rate' => 45,
                'calculated_unit_price' => 16.775,
                'actual_sales_unit_price_document' => 16.775,
                'document_currency' => 'TRY',
            ],
        ]);

        $request = $this->createSupplierRequest($procurement);
        $item = $request->items()->firstOrFail();

        app(SupplierProcurementRequestService::class)->updateRequestItems(
            $request,
            [[
                'id' => $item->id,
                'included' => true,
                'requested_quantity' => 100,
                'purchase_list_price' => '30.50',
                'discount_rate' => '55',
                'use_calculated_price' => true,
                'note' => 'Akdeniz label',
            ]],
            $this->adminUser,
            'Akdeniz label'
        );

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.procurements.supplier-requests.edit', $request->fresh()));

        $response->assertOk();
        $response->assertSee('Tedarikçi liste:');
        $response->assertSee('30,50 TL');
        $response->assertSee('Hesaplanan: <span data-calculated-display>13,73 TL</span>', false);
        $response->assertDontSee('Tedarikçi liste:</strong> 16,78 TL', false);
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
