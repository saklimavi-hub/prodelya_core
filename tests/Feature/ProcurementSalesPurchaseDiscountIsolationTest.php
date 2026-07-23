<?php

namespace Tests\Feature;

use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Services\SupplierProcurementRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementSalesPurchaseDiscountIsolationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_sales_and_purchase_discounts_remain_isolated_for_akdeniz_try_product(): void
    {
        [$supplier, $source, $raw, $variant, $procurement] = $this->createAkdenizFixture('AK-DISCOUNT-ISO');

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
                'note' => 'Akdeniz discount isolate',
            ]],
            $this->adminUser,
            'Akdeniz discount isolate'
        );

        $item->refresh();
        $procurement->refresh();
        $orderItem = $procurement->orderItem()->firstOrFail()->fresh();

        $this->assertSame(45.0, (float) $orderItem->discount_rate);
        $this->assertSame(16.78, round((float) $orderItem->unit_price, 2));
        $this->assertSame(55.0, (float) $item->discount_rate);
        $this->assertSame(13.725, round((float) $item->purchase_calculated_unit_price, 3));
        $this->assertSame(13.73, round((float) $item->purchase_unit_price, 2));
        $this->assertNotSame((float) $orderItem->discount_rate, (float) $item->discount_rate);
    }

    private function createAkdenizFixture(string $code): array
    {
        [$supplier, $source] = $this->createSupplierWithAccess($code);
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
            'source_name' => 'Akdeniz Ham Ürün',
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

        $procurement = $this->createProcurement($supplier, $source, 'SP-' . $code . '-001', [
            'product_name' => '1020 Kırmızı Metal Tükenmez Rubber Gövde Kalem',
            'product_code' => 'AK-1020-KIRMIZI',
            'quantity' => 100,
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
                'applied_rate' => 1,
            ],
        ]);

        return [$supplier, $source, $raw, $variant, $procurement];
    }
}
