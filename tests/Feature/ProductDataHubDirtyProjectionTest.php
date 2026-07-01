<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductDataHubSyncRun;
use App\Models\StandardProduct;
use App\Models\Supplier;
use App\Models\SupplierProductRaw;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantLocalStock;
use App\Models\TenantSupplierAccess;
use App\Models\User;
use App\Services\ProductDataHub\StandardProductBuilderService;
use App\Services\ProductDataHub\SupplierSourceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductDataHubDirtyProjectionTest extends TestCase
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

    public function test_command_rejects_no_project_and_project_dirty_combination(): void
    {
        $this->artisan('product-data-hub:sync-sources', [
            '--mode' => 'delta',
            '--dry-run' => true,
            '--project-dirty' => true,
            '--no-project' => true,
        ])
            ->expectsOutputToContain('--no-project ile --project-dirty birlikte kullanılamaz.')
            ->assertFailed();
    }

    public function test_delta_dry_run_with_project_dirty_reports_candidates_without_writing_catalog(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $source = $this->makeSource('DRY');
        $this->prepareXmlFixtureForSource($source, $this->akdenizXml([
            $this->record('3001', 'DRY-1', 'Dry One', 120, 100, 25),
        ]));

        $raw = $this->createRawProduct($source, '3001', 'DRY-1', 'Dry One', 100, 100, 25);
        $standard = $this->buildStandardProductFromRaw($raw);
        $this->grantAccess($tenant, $source->supplier_id, true);

        $result = app(SupplierSourceSyncService::class)->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'dry_run' => true,
            'project_dirty' => true,
        ]);

        $run = $result['run'];

        $this->assertFalse($run->exists);
        $this->assertSame('dirty', data_get($run->report_payload, 'delta_summary.projection_mode'));
        $this->assertSame('price_stock_delta', data_get($run->report_payload, 'delta_summary.projection_reason'));
        $this->assertSame(1, (int) data_get($run->report_payload, 'delta_summary.dirty_standard_products_detected'));
        $this->assertSame(1, (int) data_get($run->report_payload, 'delta_summary.dirty_standard_products_projected'));
        $this->assertSame(1, (int) data_get($run->report_payload, 'delta_summary.affected_tenants_count'));
        $this->assertSame($standard->id, $standard->id);
        $this->assertDatabaseCount('product_data_hub_sync_runs', 0);
        $this->assertDatabaseCount('tenant_catalog_products', 0);
        $this->assertDatabaseCount('tenant_catalog_product_variants', 0);
    }

    public function test_project_dirty_projects_only_price_changed_products_and_keeps_snapshots_untouched(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $blockedTenant = TenantAccount::query()->create([
            'name' => 'Blocked Tenant',
            'legal_name' => 'Blocked Tenant A.S.',
            'slug' => 'blocked-tenant-' . uniqid(),
            'panel_subdomain' => 'blocked-' . uniqid(),
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
        ]);
        $source = $this->makeSource('PRICE');
        $this->prepareXmlFixtureForSource($source, $this->akdenizXml([
            $this->record('4001', 'PRICE-1', 'Price One', 150, 120, 50),
            $this->record('4002', 'UNCHANGED-1', 'Unchanged One', 90, 80, 10),
            $this->record('4005', 'PAD-1', 'Pad One', 65, 55, 8),
            $this->record('4006', 'PAD-2', 'Pad Two', 66, 56, 9),
            $this->record('4007', 'PAD-3', 'Pad Three', 67, 57, 10),
            $this->record('4008', 'PAD-4', 'Pad Four', 68, 58, 11),
            $this->record('4003', 'NEW-1', 'New Product', 60, 55, 12),
        ]));

        $changedRaw = $this->createRawProduct($source, '4001', 'PRICE-1', 'Price One', 100, 120, 50);
        $changedStandard = $this->buildStandardProductFromRaw($changedRaw);
        $unchangedRaw = $this->createRawProduct($source, '4002', 'UNCHANGED-1', 'Unchanged One', 90, 80, 10);
        $unchangedStandard = $this->buildStandardProductFromRaw($unchangedRaw);
        $this->createRawProduct($source, '4005', 'PAD-1', 'Pad One', 65, 55, 8);
        $this->createRawProduct($source, '4006', 'PAD-2', 'Pad Two', 66, 56, 9);
        $this->createRawProduct($source, '4007', 'PAD-3', 'Pad Three', 67, 57, 10);
        $this->createRawProduct($source, '4008', 'PAD-4', 'Pad Four', 68, 58, 11);
        $missingRaw = $this->createRawProduct($source, '4004', 'MISSING-1', 'Missing One', 70, 60, 5);
        $missingStandard = $this->buildStandardProductFromRaw($missingRaw);

        $orderItem = $this->createOrderItemSnapshot($source, $changedStandard, 'PRICE-1', 'Price Snapshot', 100.0, 50.0);
        $this->grantAccess($tenant, $source->supplier_id, true);
        $this->grantAccess($blockedTenant, $source->supplier_id, false);

        $result = app(SupplierSourceSyncService::class)->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'apply_price_stock' => true,
            'project_dirty' => true,
        ]);

        $run = $result['run']->fresh();
        $orderItem->refresh();

        $catalogChanged = TenantCatalogProduct::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('standard_product_id', $changedStandard->id)
            ->first();

        $this->assertSame(ProductDataHubSyncRun::STATUS_COMPLETED, $run->normalizedStatus());
        $this->assertNotNull($catalogChanged);
        $this->assertSame(150.0, (float) $catalogChanged->display_price);
        $this->assertDatabaseMissing('tenant_catalog_products', [
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $unchangedStandard->id,
        ]);
        $this->assertDatabaseMissing('tenant_catalog_products', [
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => $missingStandard->id,
        ]);
        $this->assertDatabaseMissing('tenant_catalog_products', [
            'tenant_account_id' => $blockedTenant->id,
            'standard_product_id' => $changedStandard->id,
        ]);
        $this->assertDatabaseMissing('supplier_products_raw', [
            'supplier_source_id' => $source->id,
            'supplier_product_code' => 'NEW-1',
        ]);
        $this->assertSame('Price Snapshot', data_get($orderItem->product_snapshot, 'product_name'));
        $this->assertSame(100.0, (float) data_get($orderItem->price_snapshot, 'list_price'));
        $this->assertSame(50.0, (float) data_get($orderItem->stock_snapshot, 'visible_stock_quantity'));
        $this->assertSame('dirty', data_get($run->report_payload, 'delta_apply_summary.projection_mode'));
        $this->assertSame('price_stock_delta', data_get($run->report_payload, 'delta_apply_summary.projection_reason'));
        $this->assertSame(1, (int) data_get($run->report_payload, 'delta_apply_summary.dirty_standard_products_detected'));
        $this->assertSame(1, (int) data_get($run->report_payload, 'delta_apply_summary.dirty_standard_products_projected'));
        $this->assertSame(1, (int) data_get($run->report_payload, 'delta_apply_summary.affected_tenants_count'));
    }

    public function test_project_dirty_updates_stock_using_dirty_ids_and_preserves_local_stock_priority(): void
    {
        $tenant = TenantAccount::query()->firstOrFail();
        $source = $this->makeSource('STOCK');
        $this->prepareXmlFixtureForSource($source, $this->akdenizXml([
            $this->record('5001', 'STOCK-1', 'Stock One', 75, 60, 40),
        ]));

        $raw = $this->createRawProduct($source, '5001', 'STOCK-1', 'Stock One', 75, 60, 10);
        $standard = $this->buildStandardProductFromRaw($raw);
        $this->grantAccess($tenant, $source->supplier_id, true);

        $existingProjection = app(\App\Services\ProductDataHub\TenantCatalogProjectionService::class)->projectForTenant($tenant, [
            'supplier_ids' => [$source->supplier_id],
            'standard_product_ids' => [$standard->id],
        ]);
        $this->assertGreaterThan(0, (int) ($existingProjection['products'] ?? 0));

        $catalogProduct = TenantCatalogProduct::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('standard_product_id', $standard->id)
            ->firstOrFail();

        TenantLocalStock::query()->create([
            'tenant_account_id' => $tenant->id,
            'tenant_catalog_product_id' => $catalogProduct->id,
            'warehouse_code' => 'WH-1',
            'location_code' => 'A-01',
            'quantity_on_hand' => 7,
            'quantity_reserved' => 0,
            'quantity_available' => 7,
        ]);

        $result = app(SupplierSourceSyncService::class)->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'apply_price_stock' => true,
            'project_dirty' => true,
        ]);

        $run = $result['run']->fresh();
        $catalogProduct->refresh();

        $this->assertSame(ProductDataHubSyncRun::STATUS_COMPLETED, $run->normalizedStatus());
        $this->assertSame(40.0, (float) $catalogProduct->supplier_stock_quantity);
        $this->assertSame(7.0, (float) $catalogProduct->local_stock_quantity);
        $this->assertSame(47.0, (float) $catalogProduct->stock_quantity);
        $this->assertSame(47.0, (float) $catalogProduct->total_stock_quantity);
        $this->assertTrue((bool) $catalogProduct->local_stock_priority);
        $this->assertSame(1, (int) data_get($run->report_payload, 'delta_apply_summary.dirty_standard_products_detected'));
        $this->assertGreaterThanOrEqual(1, (int) data_get($run->report_payload, 'delta_apply_summary.tenant_catalog_products_updated'));
        $this->assertSame(0, TenantCatalogProductVariant::query()->count());
    }

    private function makeSource(string $suffix): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'Dirty Projection Supplier ' . $suffix,
            'code' => 'DIRTY-' . $suffix . '-' . uniqid(),
            'status' => 'active',
        ]);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => 'Dirty Projection Source ' . $suffix,
            'status' => 'active',
            'config' => [
                'format' => 'xml',
                'profile_key' => 'AKDENIZ',
                'source_profile_template' => 'AKDENIZ',
            ],
        ]);
    }

    private function grantAccess(TenantAccount $tenant, int $supplierId, bool $active): void
    {
        TenantSupplierAccess::query()->create([
            'tenant_account_id' => $tenant->id,
            'supplier_id' => $supplierId,
            'is_active' => $active,
            'can_view_products' => true,
            'can_request_purchase' => true,
            'can_use_in_quotes' => true,
            'visible_in_catalog' => true,
            'export_allowed' => true,
            'price_multiplier' => 1,
            'safe_stock_quantity' => 0,
        ]);
    }

    private function prepareXmlFixtureForSource(SupplierSource $source, string $content): void
    {
        $fixturesDir = storage_path('framework/testing/product-data-hub-dirty-projection-fixtures');

        if (!is_dir($fixturesDir)) {
            mkdir($fixturesDir, 0777, true);
        }

        $filePath = $fixturesDir . DIRECTORY_SEPARATOR . 'source-' . $source->id . '.xml';
        file_put_contents($filePath, $content);

        $config = $source->config ?? [];
        $config['format'] = 'xml';
        $config['source_file_path'] = $filePath;
        $config['product_node_path'] = 'RECORD';

        $source->forceFill([
            'source_type' => 'xml',
            'url' => null,
            'config' => $config,
            'status' => 'active',
        ])->save();
    }

    private function createRawProduct(SupplierSource $source, string $id, string $code, string $name, float $price, float $net, float $stock): SupplierProductRaw
    {
        return SupplierProductRaw::query()->create([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'supplier_product_id' => $id,
            'source_product_id' => $id,
            'supplier_product_code' => $code,
            'product_name' => $name,
            'source_name' => $name,
            'supplier_category_name' => 'Aksesuar',
            'source_category' => 'Aksesuar',
            'image_url' => 'https://example.test/' . strtolower($code) . '.jpg',
            'stock_quantity' => $stock,
            'source_stock' => $stock,
            'purchase_price' => $net,
            'source_price' => $price,
            'source_currency' => 'TL',
            'currency' => 'TL',
            'vat_rate' => 20,
            'normalized_payload' => [
                'list_price' => $price,
                'purchase_price' => $net,
                'net_price' => $net,
                'discount_rate' => 5,
                'currency' => 'TL',
                'vat_rate' => 20,
                'pricing_policy_type' => 'discounted_list_price',
            ],
            'import_hash' => 'dirty-' . strtolower($code),
            'sync_status' => 'processed',
        ]);
    }

    private function buildStandardProductFromRaw(SupplierProductRaw $rawProduct): StandardProduct
    {
        app(StandardProductBuilderService::class)->buildFromRawProduct($rawProduct->fresh());

        return StandardProduct::query()->findOrFail($rawProduct->fresh()->standard_product_id);
    }

    private function createOrderItemSnapshot(SupplierSource $source, StandardProduct $product, string $productCode, string $productName, float $listPrice, float $stock): OrderItem
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
            'supplier_id' => null,
            'supplier_source_id' => $source->id,
            'item_type' => 'product',
            'product_source' => 'supplier_feed',
            'product_name' => $productName,
            'product_code' => $productCode,
            'quantity' => 10,
            'unit' => 'Adet',
            'product_snapshot' => [
                'product_name' => $productName,
                'product_code' => $productCode,
            ],
            'price_snapshot' => [
                'list_price' => $listPrice,
            ],
            'stock_snapshot' => [
                'visible_stock_quantity' => $stock,
            ],
            'catalog_source' => 'tenant_catalog',
        ]);
    }

    private function record(string $id, string $code, string $name, float $price, float $net, float $stock): array
    {
        return [
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
        ];
    }

    private function akdenizXml(array $records): string
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
}
