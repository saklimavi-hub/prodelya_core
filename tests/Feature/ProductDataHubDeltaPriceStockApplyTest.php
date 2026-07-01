<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductDataHubSyncChange;
use App\Models\ProductDataHubSyncRun;
use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\ProductDataHub\StandardProductBuilderService;
use App\Services\ProductDataHub\SupplierSourceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductDataHubDeltaPriceStockApplyTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->actingAs($this->adminUser);
    }

    public function test_delta_apply_updates_only_safe_price_stock_changes_and_keeps_review_changes_as_is(): void
    {
        $source = $this->findSourceBySupplierCode('AKDENIZ');
        $this->prepareXmlFixtureForSource($source, $this->akdenizMultiRecordXml([
            [
                'urun_id' => '9001',
                'urunkodu' => 'PB-4007',
                'urunadi' => 'Wireless Mousepad',
                'pure_prodname' => 'Wireless Mousepad',
                'listefiyati' => '986,00',
                'listefiyatkapali' => '542,30',
                'iskonto' => '15',
                'netfiyat' => '542,30',
                'kur' => 'TL',
                'kdvorani' => '20',
                'stokmiktar' => '2531.0',
                'stokresim' => 'https://example.test/pb-4007-stok-new.jpg',
                'urunresim' => 'https://example.test/pb-4007-urun-new.jpg',
                'kategori' => 'Powerbanklar',
            ],
            [
                'urun_id' => '9002',
                'urunkodu' => 'REV-1',
                'urunadi' => 'Review Only Yeni Ad',
                'pure_prodname' => 'Review Only Yeni Ad',
                'listefiyati' => '50,00',
                'listefiyatkapali' => '40,00',
                'iskonto' => '5',
                'netfiyat' => '40,00',
                'kur' => 'TL',
                'kdvorani' => '20',
                'stokmiktar' => '45.0',
                'stokresim' => 'https://example.test/rev-new.jpg',
                'urunresim' => 'https://example.test/rev-new.jpg',
                'kategori' => 'Yeni Kategori',
            ],
            [
                'urun_id' => '9003',
                'urunkodu' => 'PAD-1',
                'urunadi' => 'Pad 1',
                'listefiyati' => '35,00',
                'listefiyatkapali' => '30,00',
                'iskonto' => '5',
                'netfiyat' => '30,00',
                'kur' => 'TL',
                'kdvorani' => '20',
                'stokmiktar' => '10.0',
                'stokresim' => 'https://example.test/pad1.jpg',
                'kategori' => 'Aksesuar',
            ],
            [
                'urun_id' => '9004',
                'urunkodu' => 'PAD-2',
                'urunadi' => 'Pad 2',
                'listefiyati' => '36,00',
                'listefiyatkapali' => '31,00',
                'iskonto' => '5',
                'netfiyat' => '31,00',
                'kur' => 'TL',
                'kdvorani' => '20',
                'stokmiktar' => '11.0',
                'stokresim' => 'https://example.test/pad2.jpg',
                'kategori' => 'Aksesuar',
            ],
            [
                'urun_id' => '9005',
                'urunkodu' => 'PAD-3',
                'urunadi' => 'Pad 3',
                'listefiyati' => '37,00',
                'listefiyatkapali' => '32,00',
                'iskonto' => '5',
                'netfiyat' => '32,00',
                'kur' => 'TL',
                'kdvorani' => '20',
                'stokmiktar' => '12.0',
                'stokresim' => 'https://example.test/pad3.jpg',
                'kategori' => 'Aksesuar',
            ],
            [
                'urun_id' => '9006',
                'urunkodu' => 'NEW-1',
                'urunadi' => 'Yeni Ürün',
                'listefiyati' => '49,00',
                'listefiyatkapali' => '42,00',
                'iskonto' => '5',
                'netfiyat' => '42,00',
                'kur' => 'TL',
                'kdvorani' => '20',
                'stokmiktar' => '80.0',
                'stokresim' => 'https://example.test/new.jpg',
                'kategori' => 'Yeni Gelenler',
            ],
        ]), 'RECORD');

        $rawChanged = $this->createAkdenizRawProduct($source, [
            'supplier_product_id' => '9001',
            'supplier_product_code' => 'PB-4007',
            'product_name' => 'Wireless Mousepad',
            'supplier_category_name' => 'Powerbanklar',
            'image_url' => 'https://example.test/pb-4007-old.jpg',
            'stock_quantity' => 100,
            'purchase_price' => 500,
            'currency' => 'TL',
            'vat_rate' => 20,
            'source_price' => 900,
            'source_stock' => 100,
            'normalized_payload' => [
                'list_price' => 900,
                'purchase_price' => 500,
                'net_price' => 500,
                'discount_rate' => 15,
                'currency' => 'TL',
                'vat_rate' => 20,
                'pricing_policy_type' => 'discounted_list_price',
                'net_price_warning' => false,
                'price_policy_warning' => false,
            ],
            'import_hash' => 'ak-pb-4007',
        ]);
        $standardChanged = $this->buildStandardProductFromRaw($rawChanged);
        $orderItem = $this->createOrderItemSnapshot($source, $standardChanged, [
            'product_name' => 'Wireless Mousepad Snapshot',
            'product_code' => 'PB-4007',
            'list_price' => 200.0,
            'visible_stock_quantity' => 100,
        ]);

        $this->createAkdenizRawProduct($source, [
            'supplier_product_id' => '9002',
            'supplier_product_code' => 'REV-1',
            'product_name' => 'Review Only Eski Ad',
            'supplier_category_name' => 'Eski Kategori',
            'image_url' => 'https://example.test/rev-old.jpg',
            'stock_quantity' => 45,
            'purchase_price' => 40,
            'currency' => 'TL',
            'vat_rate' => 20,
            'source_price' => 50,
            'source_stock' => 45,
            'normalized_payload' => [
                'list_price' => 50,
                'purchase_price' => 40,
                'net_price' => 40,
                'discount_rate' => 5,
                'currency' => 'TL',
                'vat_rate' => 20,
                'pricing_policy_type' => 'discounted_list_price',
            ],
            'description' => 'Eski açıklama',
            'import_hash' => 'ak-rev-1',
        ]);

        foreach ([
            ['id' => '9003', 'code' => 'PAD-1', 'name' => 'Pad 1', 'price' => 35, 'net' => 30, 'stock' => 10],
            ['id' => '9004', 'code' => 'PAD-2', 'name' => 'Pad 2', 'price' => 36, 'net' => 31, 'stock' => 11],
            ['id' => '9005', 'code' => 'PAD-3', 'name' => 'Pad 3', 'price' => 37, 'net' => 32, 'stock' => 12],
            ['id' => '9007', 'code' => 'MISSING-1', 'name' => 'Missing Ürün', 'price' => 38, 'net' => 33, 'stock' => 13],
        ] as $row) {
            $this->createAkdenizRawProduct($source, [
                'supplier_product_id' => $row['id'],
                'supplier_product_code' => $row['code'],
                'product_name' => $row['name'],
                'supplier_category_name' => 'Aksesuar',
                'image_url' => 'https://example.test/' . strtolower($row['code']) . '.jpg',
                'stock_quantity' => $row['stock'],
                'purchase_price' => $row['net'],
                'currency' => 'TL',
                'vat_rate' => 20,
                'source_price' => $row['price'],
                'source_stock' => $row['stock'],
                'normalized_payload' => [
                    'list_price' => $row['price'],
                    'purchase_price' => $row['net'],
                    'net_price' => $row['net'],
                    'discount_rate' => 5,
                    'currency' => 'TL',
                    'vat_rate' => 20,
                    'pricing_policy_type' => 'discounted_list_price',
                ],
                'import_hash' => 'ak-' . strtolower($row['code']),
            ]);
        }

        $service = app(SupplierSourceSyncService::class);
        $dryRun = $service->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'dry_run' => true,
            'no_project' => true,
        ]);
        $apply = $service->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'apply_price_stock' => true,
            'no_project' => true,
        ]);

        $applyRun = $apply['run']->fresh();
        $changedRaw = SupplierProductRaw::query()->where('supplier_product_code', 'PB-4007')->firstOrFail();
        $reviewRaw = SupplierProductRaw::query()->where('supplier_product_code', 'REV-1')->firstOrFail();
        $missingRaw = SupplierProductRaw::query()->where('supplier_product_code', 'MISSING-1')->firstOrFail();
        $changedStandard = StandardProduct::query()->findOrFail($standardChanged->id);
        $orderItem->refresh();

        $this->assertSame(ProductDataHubSyncRun::STATUS_COMPLETED, $applyRun->normalizedStatus());
        $this->assertSame(
            data_get($dryRun['run']->report_payload, 'delta_summary.counts'),
            data_get($applyRun->report_payload, 'delta_apply_summary.counts')
        );
        $this->assertSame(986.0, (float) data_get($changedRaw->normalized_payload, 'list_price'));
        $this->assertSame(542.3, (float) data_get($changedRaw->normalized_payload, 'net_price'));
        $this->assertSame(2531.0, (float) $changedRaw->stock_quantity);
        $this->assertSame('discounted_list_price', data_get($changedRaw->normalized_payload, 'pricing_policy_type'));
        $this->assertFalse((bool) data_get($changedRaw->normalized_payload, 'net_price_warning'));
        $this->assertSame(542.3, (float) data_get($changedStandard->meta, 'price_snapshot.net_price'));
        $this->assertSame('discounted_list_price', data_get($changedStandard->meta, 'price_snapshot.pricing_policy_type'));
        $this->assertSame(2531.0, (float) data_get($changedStandard->meta, 'stock_snapshot.stock_quantity'));
        $this->assertSame(986.0, (float) data_get($changedStandard->source_summary, '0.list_price'));
        $this->assertSame('Review Only Eski Ad', $reviewRaw->product_name);
        $this->assertSame('Eski Kategori', $reviewRaw->supplier_category_name);
        $this->assertSame('https://example.test/rev-old.jpg', $reviewRaw->image_url);
        $this->assertSame('Eski açıklama', $reviewRaw->description);
        $this->assertDatabaseMissing('supplier_products_raw', ['supplier_product_code' => 'NEW-1']);
        $this->assertSame('processed', $missingRaw->sync_status);
        $this->assertSame([
            'product_name' => 'Wireless Mousepad Snapshot',
            'product_code' => 'PB-4007',
        ], [
            'product_name' => data_get($orderItem->product_snapshot, 'product_name'),
            'product_code' => data_get($orderItem->product_snapshot, 'product_code'),
        ]);
        $this->assertSame(200.0, (float) data_get($orderItem->price_snapshot, 'list_price'));
        $this->assertSame(100.0, (float) data_get($orderItem->stock_snapshot, 'visible_stock_quantity'));
        $this->assertSame(0, DB::table('tenant_catalog_products')->count());
        $this->assertSame(0, DB::table('tenant_catalog_product_variants')->count());
        $this->assertSame(1, (int) data_get($applyRun->report_payload, 'delta_apply_summary.price_and_stock_changed_applied'));
        $this->assertGreaterThan(0, (int) data_get($applyRun->report_payload, 'delta_apply_summary.skipped_non_price_stock_change'));
        $this->assertTrue((bool) data_get($applyRun->report_payload, 'delta_apply_summary.projection_skipped'));
        $this->assertGreaterThan(0, ProductDataHubSyncChange::query()->where('sync_run_id', $applyRun->id)->where('change_type', 'price_and_stock_changed_applied')->count());
    }

    public function test_delta_apply_updates_pozitron_variant_price_stock_and_preserves_usd_list_price_policy(): void
    {
        $source = $this->makePozitronSource();
        $this->prepareJsonFixtureForSource($source, [[
            'id' => 1300,
            'urun_sku' => 'K1300',
            'urun_adi' => 'Kutu Set',
            'urun_aciklamasi' => 'Kutu set aciklama',
            'urun_url' => 'https://pozitronpromosyon.com/urun/k1300',
            'kategoriler' => [['id' => 41, 'ad' => 'Setler', 'slug' => 'setler']],
            'urun_gorselleri' => ['https://pozitronpromosyon.com/uploads/k1300-parent-1.jpg'],
            'urun_fiyati' => '27.50',
            'kdv_orani' => '20',
            'varyasyonlar' => [[
                'varyasyon_id' => 9001,
                'stok_kodu' => 'K1300KR',
                'renk' => 'Kirmizi',
                'stok_adedi' => 11,
                'fiyat' => '31.25',
                'gorseller' => ['https://pozitronpromosyon.com/uploads/k1300-kirmizi.jpg'],
                'urun_url' => 'https://pozitronpromosyon.com/urun/k1300?renk=kirmizi',
            ]],
        ]]);

        $rawProduct = SupplierProductRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'supplier_product_id' => '1300',
            'source_product_id' => '1300',
            'supplier_product_code' => 'K1300',
            'supplier_group_code' => 'K1300',
            'product_name' => 'Kutu Set',
            'source_name' => 'Kutu Set',
            'supplier_category_name' => 'Setler',
            'source_category' => 'Setler',
            'image_url' => 'https://pozitronpromosyon.com/uploads/k1300-parent-1.jpg',
            'stock_quantity' => 18,
            'source_stock' => 18,
            'source_price' => 27.5,
            'source_currency' => 'USD',
            'currency' => 'USD',
            'vat_rate' => 20,
            'normalized_payload' => [
                'list_price' => 27.5,
                'currency' => 'USD',
                'vat_rate' => 20,
                'pricing_policy_type' => 'list_price',
                'net_price_warning' => false,
                'price_policy_warning' => false,
                'gallery_images' => ['https://pozitronpromosyon.com/uploads/k1300-parent-1.jpg'],
            ],
            'import_hash' => 'pz-k1300',
            'sync_status' => 'processed',
        ]);
        $rawVariant = SupplierProductVariantRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'supplier_product_raw_id' => $rawProduct->id,
            'parent_supplier_product_id' => '1300',
            'supplier_group_code' => 'K1300',
            'variant_id' => '9001',
            'variant_stock_code' => 'K1300KR',
            'variant_name' => 'Kirmizi',
            'variant_color' => 'Kirmizi',
            'variant_stock_quantity' => 18,
            'variant_image_url' => 'https://pozitronpromosyon.com/uploads/k1300-kirmizi-old.jpg',
            'normalized_payload' => [
                'list_price' => 28.75,
                'currency' => 'USD',
                'vat_rate' => 20,
                'pricing_policy_type' => 'list_price',
                'variant_stock_quantity' => 18,
                'net_price_warning' => false,
            ],
            'import_hash' => 'pz-k1300kr',
            'sync_status' => 'processed',
        ]);

        $standard = $this->buildStandardProductFromRaw($rawProduct->fresh());
        $rawVariant->refresh();

        $result = app(SupplierSourceSyncService::class)->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'apply_price_stock' => true,
            'no_project' => true,
        ]);

        $run = $result['run']->fresh();
        $rawVariant->refresh();
        $standard->refresh();
        $standardVariant = StandardProductVariant::query()->findOrFail($rawVariant->standard_product_variant_id);

        $this->assertSame(ProductDataHubSyncRun::STATUS_COMPLETED, $run->normalizedStatus());
        $this->assertSame(31.25, (float) data_get($rawVariant->normalized_payload, 'list_price'));
        $this->assertSame(11.0, (float) $rawVariant->variant_stock_quantity);
        $this->assertSame('USD', data_get($rawVariant->normalized_payload, 'currency'));
        $this->assertSame('list_price', data_get($rawVariant->normalized_payload, 'pricing_policy_type'));
        $this->assertSame(31.25, (float) data_get($standardVariant->meta, 'price_snapshot.list_price'));
        $this->assertSame(11.0, (float) $standardVariant->stock_quantity);
        $this->assertSame('list_price', data_get($standardVariant->meta, 'price_snapshot.pricing_policy_type'));
        $this->assertSame('USD', data_get($standard->meta, 'normalized_payload.currency'));
        $this->assertSame(11.0, (float) $standard->total_stock_quantity);
        $this->assertTrue((bool) data_get($run->report_payload, 'delta_apply_summary.projection_skipped'));
    }

    public function test_delta_apply_rejects_identity_risky_source_without_touching_products(): void
    {
        $source = $this->findSourceBySupplierCode('ETKIN');
        $this->prepareXmlFixtureForSource($source, $this->identityRiskFixtureXml(), 'urun');

        $result = app(SupplierSourceSyncService::class)->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'apply_price_stock' => true,
            'no_project' => true,
        ]);

        $run = $result['run'];

        $this->assertTrue($run->exists);
        $this->assertSame(ProductDataHubSyncRun::STATUS_FAILED, $run->normalizedStatus());
        $this->assertGreaterThan(0, (int) data_get($run->report_payload, 'delta_apply_summary.skipped_identity_risk'));
        $this->assertSame(0, SupplierProductRaw::query()->where('supplier_source_id', $source->id)->count());
        $this->assertSame(0, ProductDataHubSyncChange::query()->where('sync_run_id', $run->id)->count());
    }

    public function test_delta_apply_rejects_suspicious_price_jump_without_updating_raw_values(): void
    {
        $source = $this->findSourceBySupplierCode('ETKIN');
        $this->prepareXmlFixtureForSource($source, $this->etkinFixtureXml([
            'urun_id' => 'ET-100',
            'urun_kodu' => 'PRC-1',
            'urun_grupkodu' => 'PRC',
            'urun_adi' => 'Kalem',
            'urun_resim' => 'https://example.test/kalem.jpg',
            'urun_kategori' => 'Kalemler',
            'urun_stok' => '25',
            'urun_fiyat' => '320.00',
        ]), 'urun');

        $raw = SupplierProductRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'supplier_product_id' => 'ET-100',
            'source_product_id' => 'ET-100',
            'supplier_product_code' => 'PRC-1',
            'supplier_group_code' => 'PRC',
            'product_name' => 'Kalem',
            'source_name' => 'Kalem',
            'supplier_category_name' => 'Kalemler',
            'source_category' => 'Kalemler',
            'image_url' => 'https://example.test/kalem.jpg',
            'stock_quantity' => 25,
            'source_stock' => 25,
            'purchase_price' => 100,
            'source_price' => 100,
            'source_currency' => 'TL',
            'currency' => 'TL',
            'vat_rate' => 20,
            'normalized_payload' => [
                'list_price' => 100,
                'purchase_price' => 100,
                'currency' => 'TL',
                'vat_rate' => 20,
                'pricing_policy_type' => 'list_price_only',
            ],
            'import_hash' => 'et-prc-1',
            'sync_status' => 'processed',
        ]);

        $result = app(SupplierSourceSyncService::class)->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'apply_price_stock' => true,
            'no_project' => true,
        ]);

        $run = $result['run'];
        $raw->refresh();

        $this->assertSame(ProductDataHubSyncRun::STATUS_FAILED, $run->normalizedStatus());
        $this->assertGreaterThan(0, (int) data_get($run->report_payload, 'delta_apply_summary.skipped_suspicious_price_jump'));
        $this->assertSame(100.0, (float) data_get($raw->normalized_payload, 'list_price'));
        $this->assertSame(0, ProductDataHubSyncChange::query()->where('sync_run_id', $run->id)->count());
    }

    public function test_delta_apply_rejects_feed_degraded_or_suspicious_feed_drop_without_updates(): void
    {
        $source = $this->findSourceBySupplierCode('ETKIN');
        $this->prepareXmlFixtureForSource($source, $this->etkinFixtureXml([
            'urun_id' => 'ET-1',
            'urun_kodu' => 'DROP-1',
            'urun_grupkodu' => 'DROP',
            'urun_adi' => 'Drop 1',
            'urun_resim' => 'https://example.test/drop1.jpg',
            'urun_kategori' => 'Kalemler',
            'urun_stok' => '10',
            'urun_fiyat' => '10.00',
        ]), 'urun');

        foreach (range(1, 10) as $index) {
            SupplierProductRaw::query()->create([
                'supplier_id' => $source->supplier_id,
                'supplier_source_id' => $source->id,
                'supplier_product_id' => 'ET-' . $index,
                'source_product_id' => 'ET-' . $index,
                'supplier_product_code' => 'DROP-' . $index,
                'supplier_group_code' => 'DROP',
                'product_name' => 'Drop ' . $index,
                'source_name' => 'Drop ' . $index,
                'supplier_category_name' => 'Kalemler',
                'source_category' => 'Kalemler',
                'image_url' => 'https://example.test/drop' . $index . '.jpg',
                'stock_quantity' => 10,
                'source_stock' => 10,
                'purchase_price' => 10,
                'source_price' => 10,
                'source_currency' => 'TL',
                'currency' => 'TL',
                'vat_rate' => 20,
                'normalized_payload' => [
                    'list_price' => 10,
                    'purchase_price' => 10,
                    'currency' => 'TL',
                    'vat_rate' => 20,
                    'pricing_policy_type' => 'list_price_only',
                ],
                'import_hash' => 'drop-' . $index,
                'sync_status' => 'processed',
            ]);
        }

        $result = app(SupplierSourceSyncService::class)->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'apply_price_stock' => true,
            'no_project' => true,
        ]);

        $run = $result['run'];

        $this->assertSame(ProductDataHubSyncRun::STATUS_FAILED, $run->normalizedStatus());
        $this->assertGreaterThan(0, (int) data_get($run->report_payload, 'delta_apply_summary.skipped_feed_degraded'));
        $this->assertSame(10, SupplierProductRaw::query()->where('supplier_source_id', $source->id)->count());
        $this->assertSame(0, ProductDataHubSyncChange::query()->where('sync_run_id', $run->id)->count());
    }

    public function test_delta_apply_preserves_etkin_warning_policy_flags_while_updating_price_stock(): void
    {
        $source = $this->findSourceBySupplierCode('ETKIN');
        $this->prepareXmlFixtureForSource($source, $this->etkinFixtureXml([
            'urun_id' => 'ET-200',
            'urun_kodu' => 'WRN-1',
            'urun_grupkodu' => 'WRN',
            'urun_adi' => 'Uyarili Kalem',
            'urun_resim' => 'https://example.test/warn.jpg',
            'urun_kategori' => 'Kalemler',
            'urun_stok' => '55',
            'urun_fiyat' => '24.90',
            'kirmiziurun' => '1',
        ]), 'urun');

        $raw = SupplierProductRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'supplier_product_id' => 'ET-200',
            'source_product_id' => 'ET-200',
            'supplier_product_code' => 'WRN-1',
            'supplier_group_code' => 'WRN',
            'product_name' => 'Uyarili Kalem',
            'source_name' => 'Uyarili Kalem',
            'supplier_category_name' => 'Kalemler',
            'source_category' => 'Kalemler',
            'image_url' => 'https://example.test/warn.jpg',
            'stock_quantity' => 25,
            'source_stock' => 25,
            'purchase_price' => 19.90,
            'source_price' => 19.90,
            'source_currency' => 'TL',
            'currency' => 'TL',
            'vat_rate' => 20,
            'warning_flag' => true,
            'normalized_payload' => [
                'list_price' => 19.90,
                'purchase_price' => 19.90,
                'currency' => 'TL',
                'vat_rate' => 20,
                'pricing_policy_type' => 'list_price_only',
                'price_policy_warning' => true,
                'supplier_warning_flag' => true,
                'supplier_warning_type' => 'red_product',
            ],
            'import_hash' => 'et-wrn-1',
            'sync_status' => 'processed',
        ]);
        $standard = $this->buildStandardProductFromRaw($raw);

        $result = app(SupplierSourceSyncService::class)->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'apply_price_stock' => true,
            'no_project' => true,
        ]);

        $run = $result['run']->fresh();
        $raw->refresh();
        $standard->refresh();

        $this->assertSame(ProductDataHubSyncRun::STATUS_COMPLETED, $run->normalizedStatus());
        $this->assertSame(24.9, (float) data_get($raw->normalized_payload, 'list_price'));
        $this->assertSame(55.0, (float) $raw->stock_quantity);
        $this->assertTrue((bool) data_get($raw->normalized_payload, 'price_policy_warning'));
        $this->assertTrue((bool) data_get($raw->normalized_payload, 'supplier_warning_flag'));
        $this->assertSame('red_product', data_get($raw->normalized_payload, 'supplier_warning_type'));
        $this->assertTrue((bool) data_get($standard->meta, 'price_snapshot.price_policy_warning'));
        $this->assertTrue((bool) data_get($standard->meta, 'price_snapshot.supplier_warning_flag'));
        $this->assertSame('supplier_special_price_warning', data_get($standard->meta, 'price_snapshot.supplier_warning_type'));
    }

    private function findSourceBySupplierCode(string $code): SupplierSource
    {
        return SupplierSource::query()
            ->whereHas('supplier', fn ($query) => $query->where('code', $code))
            ->firstOrFail();
    }

    private function prepareXmlFixtureForSource(SupplierSource $source, string $content, string $nodePath): void
    {
        $fixturesDir = storage_path('framework/testing/product-data-hub-delta-apply-fixtures');

        if (!is_dir($fixturesDir)) {
            mkdir($fixturesDir, 0777, true);
        }

        $filePath = $fixturesDir . DIRECTORY_SEPARATOR . 'source-' . $source->id . '.xml';
        file_put_contents($filePath, $content);

        $config = $source->config ?? [];
        $config['format'] = 'xml';
        $config['source_file_path'] = $filePath;
        $config['product_node_path'] = $nodePath;

        $source->forceFill([
            'source_type' => 'xml',
            'url' => null,
            'config' => $config,
            'status' => 'active',
        ])->save();
    }

    private function prepareJsonFixtureForSource(SupplierSource $source, array $payload): void
    {
        $fixturesDir = storage_path('framework/testing/product-data-hub-delta-apply-fixtures');

        if (!is_dir($fixturesDir)) {
            mkdir($fixturesDir, 0777, true);
        }

        $filePath = $fixturesDir . DIRECTORY_SEPARATOR . 'source-' . $source->id . '.json';
        file_put_contents($filePath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $config = $source->config ?? [];
        $config['format'] = 'json';
        $config['source_profile_template'] = 'POZITRON_JSON';
        $config['profile_key'] = 'POZITRON';
        $config['source_file_path'] = $filePath;
        $config['currency'] = 'USD';
        $config['pricing_policy_type'] = 'list_price';

        $source->forceFill([
            'source_type' => 'api',
            'url' => null,
            'config' => $config,
            'status' => 'active',
        ])->save();
    }

    private function createAkdenizRawProduct(SupplierSource $source, array $attributes): SupplierProductRaw
    {
        return SupplierProductRaw::query()->create(array_merge([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_product_id' => $attributes['supplier_product_id'] ?? null,
            'source_name' => $attributes['product_name'] ?? null,
            'source_category' => $attributes['supplier_category_name'] ?? null,
            'sync_status' => 'processed',
        ], $attributes));
    }

    private function makePozitronSource(): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'Pozitron Promosyon',
            'code' => 'POZITRON-TEST-' . uniqid(),
            'status' => 'active',
        ]);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'api',
            'source_name' => 'Pozitron JSON Test',
            'status' => 'active',
            'config' => [
                'format' => 'json',
                'source_profile_template' => 'POZITRON_JSON',
                'profile_key' => 'POZITRON',
                'currency' => 'USD',
                'pricing_policy_type' => 'list_price',
                'net_price_warning' => false,
            ],
        ]);
    }

    private function buildStandardProductFromRaw(SupplierProductRaw $rawProduct): StandardProduct
    {
        app(StandardProductBuilderService::class)->buildFromRawProduct($rawProduct->fresh());

        return StandardProduct::query()->findOrFail($rawProduct->fresh()->standard_product_id);
    }

    private function createOrderItemSnapshot(SupplierSource $source, StandardProduct $product, array $snapshot): OrderItem
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $order = Order::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_family' => 'promotion',
            'document_type' => 'order',
            'document_number' => 'ORD-' . uniqid(),
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        return OrderItem::query()->create([
            'tenant_account_id' => $tenant->id,
            'order_id' => $order->id,
            'standard_product_id' => $product->id,
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'item_type' => 'product',
            'product_source' => 'supplier_feed',
            'product_name' => (string) ($snapshot['product_name'] ?? $product->product_name),
            'product_code' => (string) ($snapshot['product_code'] ?? $product->standard_product_code),
            'quantity' => 10,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => (string) ($snapshot['product_name'] ?? $product->product_name),
                'product_code' => (string) ($snapshot['product_code'] ?? $product->standard_product_code),
            ],
            'price_snapshot' => [
                'list_price' => (float) ($snapshot['list_price'] ?? 0),
            ],
            'stock_snapshot' => [
                'visible_stock_quantity' => (float) ($snapshot['visible_stock_quantity'] ?? 0),
            ],
            'catalog_source' => 'tenant_catalog',
        ]);
    }

    private function akdenizMultiRecordXml(array $records): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><ROOT>';

        foreach ($records as $record) {
            $xml .= '<RECORD>';
            foreach ($record as $key => $value) {
                $xml .= sprintf('<%1$s>%2$s</%1$s>', $key, htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            }
            $xml .= '</RECORD>';
        }

        return $xml . '</ROOT>';
    }

    private function etkinFixtureXml(array $record): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urunler><urun>';

        foreach ($record as $key => $value) {
            $xml .= sprintf('<%1$s>%2$s</%1$s>', $key, htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        }

        return $xml . '</urun></urunler>';
    }

    private function identityRiskFixtureXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urunler>
    <urun>
        <urun_id></urun_id>
        <urun_kodu></urun_kodu>
        <urun_grupkodu></urun_grupkodu>
        <urun_adi>Kodsuz Ürün</urun_adi>
        <urun_resim>https://example.test/kodsuz.jpg</urun_resim>
        <urun_kategori>Kalemler</urun_kategori>
        <urun_stok>25</urun_stok>
        <urun_fiyat>9.90</urun_fiyat>
    </urun>
</urunler>
XML;
    }
}
