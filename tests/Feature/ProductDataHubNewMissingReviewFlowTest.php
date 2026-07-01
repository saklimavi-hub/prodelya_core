<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductDataHubSyncChange;
use App\Models\ProductDataHubSyncRun;
use App\Models\Role;
use App\Models\StandardProduct;
use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\ProductDataHub\StandardProductBuilderService;
use App\Services\ProductDataHub\SupplierSourceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductDataHubNewMissingReviewFlowTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private Role $tenantOwnerRole;
    private TenantAccount $demoTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenantOwnerRole = Role::query()->where('key', 'tenant_owner')->firstOrFail();
        $this->demoTenant = TenantAccount::query()->where('panel_subdomain', 'demo')->firstOrFail();
        $this->actingAs($this->adminUser);
    }

    public function test_new_and_missing_changes_are_tracked_as_review_items_without_projection_or_standard_auto_apply(): void
    {
        $source = $this->findSourceBySupplierCode('AKDENIZ');
        $this->prepareXmlFixtureForSource($source, $this->akdenizMultiRecordXml([
            $this->record('1001', 'KEEP-1', 'Keep 1', 25, 20, 50),
            $this->record('1002', 'KEEP-2', 'Keep 2', 26, 21, 40),
            $this->record('1003', 'KEEP-3', 'Keep 3', 27, 22, 30),
            $this->record('1004', 'KEEP-4', 'Keep 4', 28, 23, 20),
            $this->record('1005', 'VAR-PARENT', 'Variant Parent', 29, 24, 10, [
                'urunattr_id' => 'VAR-NEW',
                'urunattradi' => 'Yeni Renk',
                'urunattrgr' => 'VAR-PARENT',
            ]),
            $this->record('1006', 'NEW-PRODUCT', 'Yeni Ürün', 31, 26, 90),
        ]));

        foreach ([
            ['id' => '1001', 'code' => 'KEEP-1', 'name' => 'Keep 1', 'price' => 25, 'net' => 20, 'stock' => 50],
            ['id' => '1002', 'code' => 'KEEP-2', 'name' => 'Keep 2', 'price' => 26, 'net' => 21, 'stock' => 40],
            ['id' => '1003', 'code' => 'KEEP-3', 'name' => 'Keep 3', 'price' => 27, 'net' => 22, 'stock' => 30],
            ['id' => '1004', 'code' => 'KEEP-4', 'name' => 'Keep 4', 'price' => 28, 'net' => 23, 'stock' => 20],
            ['id' => '1005', 'code' => 'VAR-PARENT', 'name' => 'Variant Parent', 'price' => 29, 'net' => 24, 'stock' => 10],
            ['id' => '1007', 'code' => 'MISSING-PRODUCT', 'name' => 'Missing Product', 'price' => 32, 'net' => 27, 'stock' => 5],
        ] as $row) {
            $this->createAkdenizRawProduct($source, $row);
        }

        $variantParent = SupplierProductRaw::query()->where('supplier_source_id', $source->id)->where('supplier_product_code', 'VAR-PARENT')->firstOrFail();
        SupplierProductVariantRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'supplier_product_raw_id' => $variantParent->id,
            'parent_supplier_product_id' => '1005',
            'supplier_group_code' => 'VAR-PARENT',
            'variant_id' => 'VAR-OLD',
            'variant_stock_code' => 'VAR-OLD',
            'variant_name' => 'Eski Renk',
            'variant_stock_quantity' => 8,
            'normalized_payload' => [
                'list_price' => 29,
                'purchase_price' => 24,
                'net_price' => 24,
                'currency' => 'TL',
                'pricing_policy_type' => 'discounted_list_price',
                'variant_stock_quantity' => 8,
            ],
            'import_hash' => 'variant-old',
            'sync_status' => 'processed',
        ]);

        $missingRaw = SupplierProductRaw::query()->where('supplier_source_id', $source->id)->where('supplier_product_code', 'MISSING-PRODUCT')->firstOrFail();
        $standardMissing = $this->buildStandardProductFromRaw($missingRaw);
        $orderItem = $this->createOrderItemSnapshot($source, $standardMissing, [
            'product_name' => 'Missing Snapshot',
            'product_code' => 'MISSING-PRODUCT',
            'list_price' => 32.0,
            'visible_stock_quantity' => 5,
        ]);

        $result = app(SupplierSourceSyncService::class)->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'apply_price_stock' => true,
            'no_project' => true,
        ]);

        $run = $result['run']->fresh();
        $orderItem->refresh();
        $missingRaw->refresh();

        $this->assertSame(ProductDataHubSyncRun::STATUS_COMPLETED, $run->normalizedStatus());
        $this->assertDatabaseHas('product_data_hub_sync_changes', [
            'sync_run_id' => $run->id,
            'supplier_source_id' => $source->id,
            'change_type' => 'new_product',
            'review_status' => ProductDataHubSyncChange::REVIEW_STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('product_data_hub_sync_changes', [
            'sync_run_id' => $run->id,
            'supplier_source_id' => $source->id,
            'change_type' => 'new_variant',
            'review_status' => ProductDataHubSyncChange::REVIEW_STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('product_data_hub_sync_changes', [
            'sync_run_id' => $run->id,
            'supplier_source_id' => $source->id,
            'change_type' => 'missing_product',
            'review_status' => ProductDataHubSyncChange::REVIEW_STATUS_PENDING,
            'is_passive_candidate' => false,
            'missing_feed_run_count' => 1,
        ]);
        $this->assertDatabaseHas('product_data_hub_sync_changes', [
            'sync_run_id' => $run->id,
            'supplier_source_id' => $source->id,
            'change_type' => 'missing_variant',
            'review_status' => ProductDataHubSyncChange::REVIEW_STATUS_PENDING,
            'is_passive_candidate' => false,
            'missing_feed_run_count' => 1,
        ]);
        $this->assertDatabaseMissing('supplier_products_raw', [
            'supplier_source_id' => $source->id,
            'supplier_product_code' => 'NEW-PRODUCT',
        ]);
        $this->assertSame('processed', $missingRaw->sync_status);
        $this->assertSame(0, DB::table('tenant_catalog_products')->count());
        $this->assertSame(0, DB::table('tenant_catalog_product_variants')->count());
        $this->assertSame('Missing Snapshot', data_get($orderItem->product_snapshot, 'product_name'));
        $this->assertSame(32.0, (float) data_get($orderItem->price_snapshot, 'list_price'));
        $this->assertSame(5.0, (float) data_get($orderItem->stock_snapshot, 'visible_stock_quantity'));

        $response = $this->actingAs($this->adminUser, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.suppliers.show', ['supplier' => $source->supplier_id]));

        $response->assertOk();
        $card = $this->extractSourceCard($response->getContent(), $source->id);
        $this->assertMatchesRegularExpression('/data-review-total="([4-9]|[1-9][0-9]+)"/', $card);
        $this->assertStringContainsString('data-review-type="new_product" data-review-count="1"', $card);
        $this->assertMatchesRegularExpression('/data-review-type="new_variant" data-review-count="([1-9][0-9]*)"/', $card);
        $this->assertStringContainsString('data-review-type="missing_product" data-review-count="1"', $card);
        $this->assertStringContainsString('data-review-type="missing_variant" data-review-count="1"', $card);
        $this->assertStringContainsString('data-primary-action="Fiyat/Stok Güncelle"', $card);
        $this->assertStringContainsString('Değişimleri İncele', $card);
        $response->assertSeeText('Değişimleri İncele');
    }

    public function test_missing_changes_become_passive_candidate_after_grace_runs_but_are_not_deleted_or_passivated(): void
    {
        $source = $this->findSourceBySupplierCode('AKDENIZ');
        $this->prepareXmlFixtureForSource($source, $this->akdenizMultiRecordXml([
            $this->record('2001', 'KEEP-1', 'Keep 1', 25, 20, 50),
            $this->record('2002', 'KEEP-2', 'Keep 2', 26, 21, 40),
            $this->record('2003', 'KEEP-3', 'Keep 3', 27, 22, 30),
            $this->record('2004', 'KEEP-4', 'Keep 4', 28, 23, 20),
            $this->record('2005', 'VAR-PARENT', 'Variant Parent', 29, 24, 10, [
                'urunattr_id' => 'VAR-NEW',
                'urunattradi' => 'Yeni Renk',
                'urunattrgr' => 'VAR-PARENT',
            ]),
            $this->record('2006', 'KEEP-5', 'Keep 5', 31, 26, 90),
        ]));

        foreach ([
            ['id' => '2001', 'code' => 'KEEP-1', 'name' => 'Keep 1', 'price' => 25, 'net' => 20, 'stock' => 50],
            ['id' => '2002', 'code' => 'KEEP-2', 'name' => 'Keep 2', 'price' => 26, 'net' => 21, 'stock' => 40],
            ['id' => '2003', 'code' => 'KEEP-3', 'name' => 'Keep 3', 'price' => 27, 'net' => 22, 'stock' => 30],
            ['id' => '2004', 'code' => 'KEEP-4', 'name' => 'Keep 4', 'price' => 28, 'net' => 23, 'stock' => 20],
            ['id' => '2005', 'code' => 'VAR-PARENT', 'name' => 'Variant Parent', 'price' => 29, 'net' => 24, 'stock' => 10],
            ['id' => '2007', 'code' => 'MISSING-PRODUCT', 'name' => 'Missing Product', 'price' => 32, 'net' => 27, 'stock' => 5],
        ] as $row) {
            $this->createAkdenizRawProduct($source, $row);
        }

        $variantParent = SupplierProductRaw::query()->where('supplier_source_id', $source->id)->where('supplier_product_code', 'VAR-PARENT')->firstOrFail();
        SupplierProductVariantRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'supplier_product_raw_id' => $variantParent->id,
            'parent_supplier_product_id' => '2005',
            'supplier_group_code' => 'VAR-PARENT',
            'variant_id' => 'VAR-OLD',
            'variant_stock_code' => 'VAR-OLD',
            'variant_name' => 'Eski Renk',
            'variant_stock_quantity' => 8,
            'normalized_payload' => [
                'list_price' => 29,
                'purchase_price' => 24,
                'net_price' => 24,
                'currency' => 'TL',
                'pricing_policy_type' => 'discounted_list_price',
                'variant_stock_quantity' => 8,
            ],
            'import_hash' => 'variant-old-2',
            'sync_status' => 'processed',
        ]);

        app(SupplierSourceSyncService::class)->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'apply_price_stock' => true,
            'no_project' => true,
        ]);
        $secondRun = app(SupplierSourceSyncService::class)->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'apply_price_stock' => true,
            'no_project' => true,
        ]);

        $missingProductReview = ProductDataHubSyncChange::query()
            ->where('supplier_source_id', $source->id)
            ->where('change_type', 'missing_product')
            ->firstOrFail();
        $missingVariantReview = ProductDataHubSyncChange::query()
            ->where('supplier_source_id', $source->id)
            ->where('change_type', 'missing_variant')
            ->firstOrFail();

        $this->assertSame(ProductDataHubSyncChange::REVIEW_STATUS_PASSIVE_CANDIDATE, $missingProductReview->review_status);
        $this->assertTrue((bool) $missingProductReview->is_passive_candidate);
        $this->assertSame(2, (int) $missingProductReview->missing_feed_run_count);
        $this->assertSame(ProductDataHubSyncChange::REVIEW_STATUS_PASSIVE_CANDIDATE, $missingVariantReview->review_status);
        $this->assertTrue((bool) $missingVariantReview->is_passive_candidate);
        $this->assertSame(2, (int) $missingVariantReview->missing_feed_run_count);
        $this->assertSame(ProductDataHubSyncRun::STATUS_COMPLETED, $secondRun['run']->fresh()->normalizedStatus());
        $this->assertDatabaseHas('supplier_products_raw', [
            'supplier_source_id' => $source->id,
            'supplier_product_code' => 'MISSING-PRODUCT',
            'sync_status' => 'processed',
        ]);
    }

    public function test_feed_degraded_or_suspicious_feed_drop_keeps_missing_items_out_of_passive_candidate_mode(): void
    {
        $source = $this->findSourceBySupplierCode('ETKIN');
        $this->prepareEtkinFixtureForSource($source, $this->etkinFixtureXml([
            'urun_id' => 'ET-1',
            'urun_kodu' => 'DROP-1',
            'urun_grupkodu' => 'DROP',
            'urun_adi' => 'Drop 1',
            'urun_resim' => 'https://example.test/drop1.jpg',
            'urun_kategori' => 'Kalemler',
            'urun_stok' => '10',
            'urun_fiyat' => '10.00',
        ]));

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
                'import_hash' => 'drop-f4-' . $index,
                'sync_status' => 'processed',
            ]);
        }

        $result = app(SupplierSourceSyncService::class)->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'dry_run' => true,
            'no_project' => true,
        ]);

        $run = $result['run'];

        $this->assertFalse($run->exists);
        $this->assertTrue((bool) data_get($run->report_payload, 'delta_summary.review_summary.missing_review_blocked'));
        $this->assertSame(0, (int) data_get($run->report_payload, 'delta_summary.review_summary.passive_candidate'));
        $this->assertSame(
            'Kaynak verisi eksik görünüyor; kaybolan ürün işlemi uygulanamaz.',
            data_get($run->report_payload, 'delta_summary.review_summary.missing_review_block_reason')
        );
        $this->assertDatabaseCount('product_data_hub_sync_changes', 0);
    }

    public function test_tenant_owner_cannot_open_review_reports_screen(): void
    {
        $tenantOwner = User::query()->create([
            'name' => 'Review Tenant Owner',
            'email' => 'review-tenant-owner@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $tenantOwner->id,
            'tenant_account_id' => $this->demoTenant->id,
            'role_id' => $this->tenantOwnerRole->id,
        ]);

        $this->actingAs($tenantOwner, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.sync-reports', ['review_only' => 1]))
            ->assertForbidden();
    }

    private function findSourceBySupplierCode(string $code): SupplierSource
    {
        return SupplierSource::query()
            ->whereHas('supplier', fn ($query) => $query->where('code', $code))
            ->firstOrFail();
    }

    private function prepareXmlFixtureForSource(SupplierSource $source, string $content): void
    {
        $fixturesDir = storage_path('framework/testing/product-data-hub-new-missing-review-fixtures');

        if (!is_dir($fixturesDir)) {
            mkdir($fixturesDir, 0777, true);
        }

        $filePath = $fixturesDir . DIRECTORY_SEPARATOR . 'source-' . $source->id . '.xml';
        file_put_contents($filePath, $content);

        $config = $source->config ?? [];
        $config['format'] = 'xml';
        $config['source_file_path'] = $filePath;
        $config['product_node_path'] = 'RECORD';
        $config['sync_policy'] = array_merge((array) ($config['sync_policy'] ?? []), [
            'missing_product_grace_runs' => 2,
        ]);

        $source->forceFill([
            'source_type' => 'xml',
            'url' => null,
            'config' => $config,
            'status' => 'active',
        ])->save();
    }

    private function prepareEtkinFixtureForSource(SupplierSource $source, string $content): void
    {
        $fixturesDir = storage_path('framework/testing/product-data-hub-new-missing-review-fixtures');

        if (!is_dir($fixturesDir)) {
            mkdir($fixturesDir, 0777, true);
        }

        $filePath = $fixturesDir . DIRECTORY_SEPARATOR . 'source-' . $source->id . '-etkin.xml';
        file_put_contents($filePath, $content);

        $config = $source->config ?? [];
        $config['format'] = 'xml';
        $config['source_file_path'] = $filePath;
        $config['product_node_path'] = 'urun';
        $config['sync_policy'] = array_merge((array) ($config['sync_policy'] ?? []), [
            'missing_product_grace_runs' => 2,
        ]);

        $source->forceFill([
            'source_type' => 'xml',
            'url' => null,
            'config' => $config,
            'status' => 'active',
        ])->save();
    }

    private function createAkdenizRawProduct(SupplierSource $source, array $attributes): SupplierProductRaw
    {
        return SupplierProductRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'supplier_product_id' => $attributes['id'],
            'source_product_id' => $attributes['id'],
            'supplier_product_code' => $attributes['code'],
            'product_name' => $attributes['name'],
            'source_name' => $attributes['name'],
            'supplier_category_name' => 'Aksesuar',
            'source_category' => 'Aksesuar',
            'image_url' => 'https://example.test/' . strtolower($attributes['code']) . '.jpg',
            'stock_quantity' => $attributes['stock'],
            'source_stock' => $attributes['stock'],
            'purchase_price' => $attributes['net'],
            'source_price' => $attributes['price'],
            'source_currency' => 'TL',
            'currency' => 'TL',
            'vat_rate' => 20,
            'normalized_payload' => [
                'list_price' => $attributes['price'],
                'purchase_price' => $attributes['net'],
                'net_price' => $attributes['net'],
                'discount_rate' => 5,
                'currency' => 'TL',
                'vat_rate' => 20,
                'pricing_policy_type' => 'discounted_list_price',
            ],
            'import_hash' => 'review-' . strtolower($attributes['code']),
            'sync_status' => 'processed',
        ]);
    }

    private function buildStandardProductFromRaw(SupplierProductRaw $rawProduct): StandardProduct
    {
        app(StandardProductBuilderService::class)->buildFromRawProduct($rawProduct->fresh());

        return StandardProduct::query()->findOrFail($rawProduct->fresh()->standard_product_id);
    }

    private function createOrderItemSnapshot(SupplierSource $source, StandardProduct $product, array $snapshot): OrderItem
    {
        $order = Order::query()->create([
            'tenant_account_id' => $this->demoTenant->id,
            'order_family' => 'promotion',
            'document_type' => 'order',
            'document_number' => 'ORD-' . uniqid(),
            'currency' => 'TL',
            'created_by' => $this->adminUser->id,
        ]);

        return OrderItem::query()->create([
            'tenant_account_id' => $this->demoTenant->id,
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

    private function record(string $id, string $code, string $name, float $price, float $net, float $stock, array $overrides = []): array
    {
        return array_merge([
            'urun_id' => $id,
            'urunkodu' => $code,
            'urunadi' => $name,
            'pure_prodname' => $name,
            'listefiyati' => number_format($price, 2, ',', ''),
            'listefiyatkapali' => number_format($net, 2, ',', ''),
            'iskonto' => '5',
            'netfiyat' => number_format($net, 2, ',', ''),
            'kur' => 'TL',
            'kdvorani' => '20',
            'stokmiktar' => (string) $stock,
            'stokresim' => 'https://example.test/' . strtolower($code) . '-stok.jpg',
            'urunresim' => 'https://example.test/' . strtolower($code) . '-urun.jpg',
            'kategori' => 'Aksesuar',
        ], $overrides);
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

    private function extractSourceCard(string $html, int $sourceId): string
    {
        $pattern = '/<article class="pd-source-row pd-source-row-stepper" data-flow-source="' . preg_quote((string) $sourceId, '/') . '">(.+?)<\/article>/su';

        $this->assertSame(1, preg_match($pattern, $html, $matches), 'Source card not found for source #' . $sourceId);

        return $matches[0];
    }
}
