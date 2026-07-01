<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StandardProduct;
use App\Models\StandardProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\ProductDataHub\StandardProductBuilderService;
use App\Services\ProductDataHub\SupplierSourceSyncService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ProductDataHubPriceStockApplyReviewGateTest extends TestCase
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

    public function test_review_columns_exist_in_sync_changes_schema(): void
    {
        $this->assertTrue(Schema::hasColumns('product_data_hub_sync_changes', [
            'review_status',
            'review_payload',
            'reviewed_at',
            'resolved_at',
            'missing_feed_run_count',
            'is_passive_candidate',
        ]));
    }

    public function test_apply_route_returns_safe_error_message_when_sync_service_throws(): void
    {
        $source = $this->makeEtkinSource();

        $mock = Mockery::mock(SupplierSourceSyncService::class);
        $mock->shouldReceive('syncSource')
            ->once()
            ->andThrow(new \RuntimeException('schema mismatch'));
        $this->app->instance(SupplierSourceSyncService::class, $mock);

        $response = $this->actingAs($this->adminUser, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.product-data-hub.sources.apply-price-stock', $source));

        $response->assertRedirect(route('admin.super.product-data-hub.sources.sync-reports', ['source_id' => $source->id]));
        $response->assertSessionHas('error', 'Fiyat/stok güncelleme tamamlanamadı. Sistem kayıt şeması veya güvenlik kontrolü nedeniyle işlem durduruldu. Detaylar Senkron / Raporlar ekranında görülebilir.');
    }

    public function test_review_only_blocked_required_field_missing_does_not_block_price_stock_apply_for_etkin_0506_l(): void
    {
        $source = $this->makeEtkinSource();
        $this->prepareJsonFixtureForSource($source, [
            [
                'urun_id' => 4516,
                'kategori_id' => 83,
                'kategori_adi' => 'Plastik Kalemler',
                'urun_kodu' => '0506-L',
                'urun_kodgrup' => '0506',
                'urun_isim' => 'Plastik Kalem',
                'urun_baslik' => '0506-L Plastik Kalem',
                'urun_aciklama' => 'Test kalem',
                'urun_renk' => 'Lacivert',
                'urun_ebat' => '',
                'toplam_stok' => 49800,
                'urun_fiyat' => '9.200',
                'urun_fiyat_virgul' => '9,200',
                'fiyat_kdv' => 20,
                'kirmiziurun' => 0,
                'urun_trase' => 'https://example.test/0506.pdf',
                'katalog_sayfa_no' => 177,
                'resim1' => 'https://example.test/0506_lacivert.jpg',
                'md5' => 'hash-0506-l',
            ],
            [
                'urun_id' => 99991,
                'kategori_id' => 83,
                'kategori_adi' => 'Plastik Kalemler',
                'urun_kodu' => 'MISSING-PRICE-1',
                'urun_kodgrup' => 'MISSING-PRICE',
                'urun_isim' => 'Eksik Fiyatli Yeni Urun',
                'urun_baslik' => 'Eksik Fiyatli Yeni Urun',
                'urun_aciklama' => 'Eksik alan',
                'urun_renk' => 'Siyah',
                'urun_ebat' => '',
                'toplam_stok' => 12,
                'urun_fiyat' => '',
                'urun_fiyat_virgul' => '',
                'fiyat_kdv' => 20,
                'kirmiziurun' => 0,
                'urun_trase' => '',
                'katalog_sayfa_no' => 177,
                'resim1' => 'https://example.test/malformed.jpg',
                'md5' => 'hash-malformed',
            ],
        ]);

        $rawProduct = $this->createEtkinRawProduct($source, [
            'supplier_product_id' => 4516,
            'supplier_product_code' => '0506-L',
            'supplier_group_code' => '0506',
            'product_name' => 'Plastik Kalem',
            'supplier_category_name' => 'Plastik Kalemler',
            'image_url' => 'https://example.test/0506-parent-old.jpg',
            'stock_quantity' => 107500,
            'purchase_price' => 6.5,
            'currency' => 'TL',
            'source_price' => 6.5,
            'source_stock' => 107500,
            'normalized_payload' => [
                'list_price' => 6.5,
                'purchase_price' => 6.5,
                'currency' => 'TL',
                'pricing_policy_type' => 'list_price_only',
                'stock_quantity' => 107500,
                'total_variant_stock_quantity' => 107500,
            ],
            'import_hash' => 'et-0506-parent',
        ]);

        $rawVariant = $this->createEtkinRawVariant($source, $rawProduct, [
            'parent_supplier_product_id' => 4516,
            'supplier_group_code' => '0506',
            'variant_code' => '0506-L',
            'variant_stock_code' => '0506-L',
            'generated_variant_code' => 'ET-0506-L',
            'variant_name' => '0506-L Plastik Kalem',
            'variant_color' => 'Lacivert',
            'variant_stock_quantity' => 107500,
            'normalized_payload' => [
                'list_price' => 6.5,
                'purchase_price' => 6.5,
                'currency' => 'TL',
                'pricing_policy_type' => 'list_price_only',
                'variant_stock_quantity' => 107500,
            ],
            'import_hash' => 'et-0506-l',
        ]);

        $standardProduct = $this->buildStandardProductFromRaw($rawProduct);
        $standardVariant = StandardProductVariant::query()
            ->where('standard_product_id', $standardProduct->id)
            ->where('generated_variant_code', 'ET-0506-L')
            ->firstOrFail();

        $rawVariant->forceFill([
            'standard_product_variant_id' => $standardVariant->id,
        ])->save();

        $service = app(SupplierSourceSyncService::class);

        $dryRun = $service->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'dry_run' => true,
            'no_project' => true,
        ]);

        $this->assertGreaterThan(0, (int) data_get($dryRun['run']->report_payload, 'delta_summary.counts.price_and_stock_changed', 0));

        $apply = $service->syncSource($source, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'apply_price_stock' => true,
            'no_project' => true,
        ]);

        $run = $apply['run']->fresh();
        $rawVariant->refresh();
        $standardVariant->refresh();

        $this->assertSame(9.2, (float) data_get($rawVariant->normalized_payload, 'list_price'));
        $this->assertSame(49800.0, (float) $rawVariant->variant_stock_quantity);
        $this->assertSame(9.2, (float) $standardVariant->min_purchase_price);
        $this->assertSame(49800.0, (float) $standardVariant->stock_quantity);
        $this->assertGreaterThanOrEqual(1, (int) data_get($run->report_payload, 'delta_apply_summary.price_stock_applied'));
        $this->assertGreaterThanOrEqual(1, (int) data_get($run->report_payload, 'delta_apply_summary.review_only_changes_detected'));
        $this->assertGreaterThanOrEqual(1, (int) data_get($run->report_payload, 'delta_apply_summary.skipped_review_only_changes'));
    }

    public function test_feed_degraded_and_suspicious_price_jump_still_block_apply(): void
    {
        $degradedSource = $this->makeEtkinSource('ETKIN-DEGRADED');
        $this->prepareJsonFixtureForSource($degradedSource, [[
            'urun_id' => 1,
            'kategori_id' => 1,
            'kategori_adi' => 'Kalemler',
            'urun_kodu' => 'ONE-1',
            'urun_kodgrup' => 'ONE',
            'urun_isim' => 'Tek Urun',
            'urun_baslik' => 'Tek Urun',
            'urun_aciklama' => '',
            'urun_renk' => '',
            'urun_ebat' => '',
            'toplam_stok' => 5,
            'urun_fiyat' => '10.000',
            'urun_fiyat_virgul' => '10,000',
            'fiyat_kdv' => 20,
            'kirmiziurun' => 0,
            'urun_trase' => '',
            'katalog_sayfa_no' => 1,
            'resim1' => 'https://example.test/one.jpg',
            'md5' => 'one-1',
        ]]);
        for ($i = 1; $i <= 20; $i++) {
            $this->createEtkinRawProduct($degradedSource, [
                'supplier_product_id' => 1000 + $i,
                'supplier_product_code' => 'OLD-' . $i,
                'supplier_group_code' => 'OLD-' . $i,
                'product_name' => 'Eski Ürün ' . $i,
                'supplier_category_name' => 'Kalemler',
                'stock_quantity' => 5,
                'purchase_price' => 10,
                'currency' => 'TL',
                'source_price' => 10,
                'source_stock' => 5,
                'normalized_payload' => ['list_price' => 10, 'currency' => 'TL', 'pricing_policy_type' => 'list_price_only'],
                'import_hash' => 'old-' . $i,
            ]);
        }

        $degradedRun = app(SupplierSourceSyncService::class)->syncSource($degradedSource, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'apply_price_stock' => true,
            'no_project' => true,
        ])['run'];

        $this->assertSame('failed', $degradedRun->normalizedStatus());
        $this->assertStringContainsString('feed_degraded', (string) $degradedRun->error_message);

        $jumpSource = $this->makeEtkinSource('ETKIN-JUMP');
        $this->prepareJsonFixtureForSource($jumpSource, [[
            'urun_id' => 5516,
            'kategori_id' => 83,
            'kategori_adi' => 'Plastik Kalemler',
            'urun_kodu' => '0506-L',
            'urun_kodgrup' => '0506',
            'urun_isim' => 'Plastik Kalem',
            'urun_baslik' => '0506-L Plastik Kalem',
            'urun_aciklama' => 'Test kalem',
            'urun_renk' => 'Lacivert',
            'urun_ebat' => '',
            'toplam_stok' => 49800,
            'urun_fiyat' => '999.000',
            'urun_fiyat_virgul' => '999,000',
            'fiyat_kdv' => 20,
            'kirmiziurun' => 0,
            'urun_trase' => '',
            'katalog_sayfa_no' => 177,
            'resim1' => 'https://example.test/0506_lacivert.jpg',
            'md5' => 'hash-jump',
        ]]);

        $jumpRaw = $this->createEtkinRawProduct($jumpSource, [
            'supplier_product_id' => 5516,
            'supplier_product_code' => '0506-L',
            'supplier_group_code' => '0506',
            'product_name' => 'Plastik Kalem',
            'supplier_category_name' => 'Plastik Kalemler',
            'stock_quantity' => 10,
            'purchase_price' => 9.2,
            'currency' => 'TL',
            'source_price' => 9.2,
            'source_stock' => 10,
            'normalized_payload' => ['list_price' => 9.2, 'currency' => 'TL', 'pricing_policy_type' => 'list_price_only'],
            'import_hash' => 'jump-parent',
        ]);
        $this->buildStandardProductFromRaw($jumpRaw);

        $jumpRun = app(SupplierSourceSyncService::class)->syncSource($jumpSource, [
            'run_type' => 'manual',
            'mode' => 'delta',
            'apply_price_stock' => true,
            'no_project' => true,
        ])['run'];

        $this->assertSame('failed', $jumpRun->normalizedStatus());
        $this->assertStringContainsString('suspicious_price_jump', (string) $jumpRun->error_message);
    }

    public function test_tenant_owner_cannot_run_apply_route(): void
    {
        $source = $this->makeEtkinSource();
        $tenantOwner = User::query()->create([
            'name' => 'Review Gate Tenant Owner',
            'email' => 'review-gate-tenant-owner@example.test',
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
            ->post(route('admin.super.product-data-hub.sources.apply-price-stock', $source))
            ->assertForbidden();
    }

    private function makeEtkinSource(string $code = 'ETKIN-RG'): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'Etkin Review Gate Supplier ' . $code,
            'code' => $code,
            'status' => 'active',
        ]);

        return SupplierSource::query()->create([
            'supplier_id' => $supplier->id,
            'source_type' => 'api',
            'source_name' => 'Etkin Review Gate Source ' . $code,
            'status' => 'active',
            'config' => [
                'format' => 'json',
                'profile_key' => 'ETKIN',
                'source_profile_template' => 'ETKIN',
                'sync_policy' => ['sync_frequency' => 'daily'],
                'enrich_gallery_from_product_page' => false,
            ],
        ]);
    }

    private function prepareJsonFixtureForSource(SupplierSource $source, array $rows): void
    {
        $fixturesDir = storage_path('framework/testing/product-data-hub-review-gate-fixtures');

        if (!is_dir($fixturesDir)) {
            mkdir($fixturesDir, 0777, true);
        }

        $filePath = $fixturesDir . DIRECTORY_SEPARATOR . 'source-' . $source->id . '.json';
        file_put_contents($filePath, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $config = $source->config ?? [];
        $config['source_file_path'] = $filePath;
        $config['format'] = 'json';
        $source->forceFill([
            'url' => null,
            'config' => $config,
        ])->save();
    }

    private function createEtkinRawProduct(SupplierSource $source, array $attributes): SupplierProductRaw
    {
        return SupplierProductRaw::query()->create(array_merge([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'source_product_id' => (string) ($attributes['supplier_product_id'] ?? $attributes['supplier_product_code'] ?? 'ETKIN-RAW'),
            'source_name' => $attributes['product_name'] ?? 'Etkin Product',
            'source_category' => $attributes['supplier_category_name'] ?? 'Plastik Kalemler',
            'source_price' => $attributes['source_price'] ?? $attributes['purchase_price'] ?? 0,
            'source_currency' => $attributes['currency'] ?? 'TL',
            'source_stock' => $attributes['source_stock'] ?? $attributes['stock_quantity'] ?? 0,
            'supplier_product_id' => $attributes['supplier_product_id'] ?? null,
            'supplier_product_code' => $attributes['supplier_product_code'] ?? null,
            'supplier_group_code' => $attributes['supplier_group_code'] ?? $attributes['supplier_product_code'] ?? null,
            'product_name' => $attributes['product_name'] ?? 'Etkin Product',
            'supplier_category_name' => $attributes['supplier_category_name'] ?? 'Plastik Kalemler',
            'stock_quantity' => $attributes['stock_quantity'] ?? 0,
            'purchase_price' => $attributes['purchase_price'] ?? 0,
            'currency' => $attributes['currency'] ?? 'TL',
            'image_url' => $attributes['image_url'] ?? null,
            'normalized_payload' => $attributes['normalized_payload'] ?? [],
            'import_hash' => $attributes['import_hash'] ?? uniqid('etkin-raw-', true),
            'sync_status' => 'processed',
        ], $attributes));
    }

    private function createEtkinRawVariant(SupplierSource $source, SupplierProductRaw $product, array $attributes): SupplierProductVariantRaw
    {
        return SupplierProductVariantRaw::query()->create(array_merge([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'supplier_product_raw_id' => $product->id,
            'parent_supplier_product_id' => $attributes['parent_supplier_product_id'] ?? $product->supplier_product_id,
            'supplier_group_code' => $attributes['supplier_group_code'] ?? $product->supplier_group_code,
            'variant_code' => $attributes['variant_code'] ?? null,
            'variant_stock_code' => $attributes['variant_stock_code'] ?? $attributes['variant_code'] ?? null,
            'generated_variant_code' => $attributes['generated_variant_code'] ?? null,
            'variant_name' => $attributes['variant_name'] ?? null,
            'variant_color' => $attributes['variant_color'] ?? null,
            'variant_stock_quantity' => $attributes['variant_stock_quantity'] ?? 0,
            'normalized_payload' => $attributes['normalized_payload'] ?? [],
            'import_hash' => $attributes['import_hash'] ?? uniqid('etkin-var-', true),
            'sync_status' => 'processed',
        ], $attributes));
    }

    private function buildStandardProductFromRaw(SupplierProductRaw $rawProduct): StandardProduct
    {
        app(StandardProductBuilderService::class)->buildFromRawProduct($rawProduct);

        return StandardProduct::query()->findOrFail($rawProduct->fresh()->standard_product_id);
    }
}
