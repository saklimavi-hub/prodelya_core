<?php

namespace Tests\Feature;

use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Services\Procurement\SupplierPurchasePriceSourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class AkdenizSupplierGrossListPriceMappingTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_akdeniz_exact_variant_maps_gross_list_separately_from_feed_net_price(): void
    {
        [$supplier, $source] = $this->createSupplierWithAccess('AK-GROSS-MAP');
        $source->update(['config' => ['profile_key' => 'AKDENIZ']]);

        $rawPayload = [
            'urunkodu' => '1020',
            'urunattrgr' => '1020',
            'urunattradi' => '1020 Kırmızı',
            'urunadi' => '1020 Kırmızı Metal Tükenmez Rubber Gövde Kalem',
            'pure_prodname' => 'Metal Tükenmez Rubber Gövde Kalem',
            'listefiyati' => '30.50',
            'listefiyatkapali' => '30.50',
            'netfiyat' => '16.775',
            'iskonto' => '45',
            'kur' => 'TL',
            'kdvorani' => '20',
        ];

        $normalizedPayload = [
            'supplier_product_code' => '1020',
            'supplier_group_code' => '1020',
            'variant_name' => '1020 Kırmızı',
            'product_name' => '1020 Kırmızı Metal Tükenmez Rubber Gövde Kalem',
            'base_product_name' => 'Metal Tükenmez Rubber Gövde Kalem',
            'list_price' => 30.50,
            'closed_list_price' => 30.50,
            'purchase_price' => 16.775,
            'net_price' => 16.775,
            'currency' => 'TL',
            'profile_key' => 'AKDENIZ',
        ];

        $raw = SupplierProductRaw::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'supplier_source_id' => $source->id,
            'source_product_id' => 'SRC-' . uniqid(),
            'source_name' => 'Akdeniz Ham Ürün',
            'supplier_product_code' => '1020',
            'product_name' => '1020 Kırmızı Metal Tükenmez Rubber Gövde Kalem',
            'purchase_price' => '16.7750',
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

        $procurement = $this->createProcurement($supplier, $source, 'SP-AK-GROSS-MAP-001', [
            'product_name' => '1020 Kırmızı Metal Tükenmez Rubber Gövde Kalem',
            'product_code' => 'AK-1020-KIRMIZI',
            'product_snapshot' => [
                'product_name' => '1020 Kırmızı Metal Tükenmez Rubber Gövde Kalem',
                'product_code' => 'AK-1020-KIRMIZI',
                'supplier_name' => $supplier->name,
                'supplier_product_raw_id' => $raw->id,
                'supplier_product_variant_raw_id' => $variant->id,
            ],
        ]);

        $resolved = app(SupplierPurchasePriceSourceResolver::class)->resolveForProcurement($procurement->fresh(['orderItem']));

        $this->assertSame('resolved', $resolved['resolution_status']);
        $this->assertSame(30.50, (float) $resolved['amount_original']);
        $this->assertSame('TRY', $resolved['currency_original']);
        $this->assertSame('listefiyati', $resolved['source_field']);
        $this->assertSame('supplier_list_price', $resolved['source_kind']);
        $this->assertNotSame(16.775, round((float) $resolved['amount_original'], 3));
    }
}
