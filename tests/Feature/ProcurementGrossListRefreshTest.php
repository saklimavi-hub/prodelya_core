<?php

namespace Tests\Feature;

use App\Models\CurrentAccountTransaction;
use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Services\SupplierProcurementCurrentAccountSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithProcurementFixtures;
use Tests\TestCase;

class ProcurementGrossListRefreshTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProcurementFixtures;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementFixtures();
    }

    public function test_refresh_rebuilds_akdeniz_legacy_draft_from_gross_list_not_feed_net(): void
    {
        [$supplier, $source, $raw, $variant, $procurement] = $this->createAkdenizFixture('AK-GROSS-REFRESH');

        $request = $this->createSupplierRequest($procurement);
        $item = $request->items()->firstOrFail();
        $item->forceFill(['discount_rate' => 55])->save();
        $item = $this->convertItemToLegacyDraftState($item, '16.78', '1678.00');

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.procurements.supplier-requests.refresh-prices', $request->fresh()));

        $response->assertRedirect(route('admin.procurements.supplier-requests.edit', $request));
        $response->assertSessionHas('success');

        $item->refresh();

        $this->assertSame(30.50, (float) $item->purchase_source_amount);
        $this->assertSame('TRY', $item->purchase_source_currency);
        $this->assertSame(30.50, round((float) $item->purchase_list_price_try, 2));
        $this->assertSame(13.725, round((float) $item->purchase_calculated_unit_price, 3));
        $this->assertSame(13.73, round((float) $item->purchase_unit_price, 2));
        $this->assertSame('listefiyati', data_get($item->purchase_price_snapshot, 'source_field'));
        $this->assertSame('supplier_list_price', data_get($item->purchase_price_snapshot, 'source_kind'));
        $this->assertNotSame(16.78, round((float) $item->purchase_source_amount, 2));

        $transaction = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('source_type', SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $item->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('1372.50', (string) $transaction->amount);
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
            'product_snapshot' => [
                'product_name' => '1020 Kırmızı Metal Tükenmez Rubber Gövde Kalem',
                'product_code' => 'AK-1020-KIRMIZI',
                'supplier_name' => $supplier->name,
                'supplier_product_raw_id' => $raw->id,
                'supplier_product_variant_raw_id' => $variant->id,
            ],
        ]);

        return [$supplier, $source, $raw, $variant, $procurement];
    }

    private function convertItemToLegacyDraftState($item, string $unitPrice, string $total)
    {
        $item->forceFill([
            'purchase_source_amount' => null,
            'purchase_source_currency' => null,
            'purchase_fx_rate' => null,
            'purchase_fx_rate_date' => null,
            'purchase_fx_rate_source' => null,
            'purchase_list_price_try' => null,
            'purchase_calculated_unit_price' => null,
            'purchase_manual_unit_price' => null,
            'purchase_manual_override' => false,
            'purchase_manual_override_reason' => null,
            'purchase_price_snapshot' => [],
            'purchase_price_snapshot_version' => 0,
            'purchase_list_price' => $unitPrice,
            'purchase_unit_price' => $unitPrice,
            'purchase_total' => $total,
        ])->save();

        $transaction = CurrentAccountTransaction::query()
            ->where('tenant_account_id', $item->tenant_account_id)
            ->where('source_type', SupplierProcurementCurrentAccountSyncService::SOURCE_TYPE)
            ->where('source_id', $item->id)
            ->latest('id')
            ->first();

        if ($transaction) {
            $transaction->forceFill([
                'amount' => $total,
                'status' => CurrentAccountTransaction::STATUS_OPEN,
            ])->save();
        }

        return $item->fresh();
    }
}
