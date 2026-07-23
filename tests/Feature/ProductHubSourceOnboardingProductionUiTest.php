<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierFieldMapping;
use App\Models\SupplierSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductHubSourceOnboardingProductionUiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
    }

    public function test_source_index_paginates_to_twenty_rows_and_defaults_to_first_visible_source_with_internal_scroll_contract(): void
    {
        $sources = $this->createManySources(45);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.index', [
                'filter' => 'all',
                'search' => 'PH Source',
                'per_page' => 20,
            ]));

        $response->assertOk();
        $response->assertSeeText('Kaynak Kartları');
        $response->assertSeeText('1-20 / 45 kaynak');
        $response->assertSeeText('PH Source 01');
        $response->assertSeeText('PH Source 20');
        $response->assertDontSeeText('PH Source 21');
        $response->assertSeeText('Detaya Git');
        $response->assertDontSeeText('Bu kaynağı aç');

        $html = $response->getContent();
        $listSegment = explode('<section class="pd-card pd-ph-source-main">', $html)[0] ?? $html;
        $this->assertStringContainsString('pd-ph-source-list-panel', $html);
        $this->assertStringContainsString('pd-ph-source-list-scroll', $html);
        $this->assertStringContainsString('pd-ph-source-list-pagination', $html);
        $this->assertStringContainsString('source_id=' . $sources[0]->id, $html);
        $this->assertStringNotContainsString('https://secret-01.example.test/feed.xml?token=tok-01', $listSegment);
        $this->assertStringNotContainsString('\\r\\n', $html);
        $this->assertSame(0, substr_count($listSegment, 'Tam konum'));
        $this->assertSame(0, substr_count($listSegment, 'Son Sync'));

        $css = file_get_contents(public_path('css/prodelya-admin.css'));
        $this->assertNotFalse($css);
        $this->assertStringContainsString('.pd-ph-source-list-panel', $css);
        $this->assertStringContainsString('.pd-ph-source-list-scroll', $css);
        $this->assertStringContainsString('.pd-ph-source-list-pagination', $css);
        $this->assertStringContainsString('max-height: calc(100vh - 250px);', $css);
        $this->assertStringContainsString('@media (max-width: 767px)', $css);
        $this->assertStringContainsString('max-height: 320px;', $css);
    }

    public function test_source_index_preserves_query_params_in_pagination_and_selects_requested_source_on_page_two(): void
    {
        $sources = $this->createManySources(45);
        $selected = $sources[24];

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.index', [
                'filter' => 'all',
                'search' => 'PH Source',
                'per_page' => 20,
                'page' => 2,
                'format' => 'XML',
                'status' => 'all',
                'readiness' => 'all',
                'sort' => 'supplier',
                'source_id' => $selected->id,
            ]));

        $response->assertOk();
        $response->assertSeeText('21-40 / 45 kaynak');
        $response->assertSeeText('PH Source 21');
        $response->assertSeeText('PH Source 40');
        $response->assertDontSeeText('PH Source 20');
        $response->assertSeeText('PH Source 25');

        $html = $response->getContent();
        $this->assertStringContainsString('page=3', $html);
        $this->assertStringContainsString('per_page=20', $html);
        $this->assertStringContainsString('format=XML', $html);
        $this->assertStringContainsString('source_id=' . $selected->id, $html);
        $this->assertStringContainsString('aria-current="true"', $html);
    }

    public function test_source_index_falls_back_to_first_visible_source_when_selected_source_is_not_on_current_page(): void
    {
        $sources = $this->createManySources(45);
        $outOfPageSelection = $sources[0];

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.index', [
                'filter' => 'all',
                'search' => 'PH Source',
                'per_page' => 20,
                'page' => 2,
                'source_id' => $outOfPageSelection->id,
            ]));

        $response->assertOk();
        $response->assertSeeText('PH Source 21');
        $response->assertDontSeeText('PH Source 01');

        $html = $response->getContent();
        $this->assertStringContainsString('Seçili hazırlık alanı', $html);
        $this->assertStringContainsString('PH Source 21', $html);
    }

    public function test_explicit_ready_source_selection_shows_disabled_first_import_ready_state(): void
    {
        $readySource = $this->createSource('PH1B1-READY', 'Hazır Kaynak', [
            'url' => 'https://example.test/ready.xml',
            'config' => [
                'profile_key' => 'AKDENIZ',
                'source_profile_template' => 'AKDENIZ',
                'format' => 'xml',
                'product_node_path' => 'RECORD',
            ],
        ]);
        $this->insertPreviewLog($readySource, 'success');
        $this->addRequiredMappings($readySource);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.index', [
                'filter' => 'all',
                'source_id' => $readySource->id,
            ]));

        $response->assertOk();
        $response->assertSeeText('Hazır Kaynak');
        $response->assertSeeText('Ürünleri Senkronize Et');
        $response->assertSeeText('Uygun ürünler, Abone Firmanın aktif tedarikçi erişimine göre kataloğa otomatik yansır.');
    }

    public function test_preview_shows_gross_list_net_reference_currency_and_no_write_copy(): void
    {
        $source = $this->makeAkdenizFixtureSource();

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.preview', $source));

        $response->assertOk();
        $response->assertSeeText('Brüt Liste Fiyatı / Net Referans');
        $response->assertSeeText('Net Referans');
        $response->assertSeeText('Para Birimi');
        $response->assertSeeText('Önizleme denemesi sistem günlüğüne kaydedilebilir.');
        $response->assertSeeText('Gelişmiş İşlemler');
        $response->assertSeeText('Staging’e Aktar');
    }

    public function test_create_and_edit_use_onboarding_group_titles_and_security_copy(): void
    {
        $source = $this->createSource('PH1B1-EDIT', 'Düzenlenecek Kaynak', [
            'url' => 'https://example.test/edit.xml',
            'config' => [
                'profile_key' => 'AKDENIZ',
                'source_profile_template' => 'AKDENIZ',
                'format' => 'xml',
            ],
        ]);

        $create = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.create'));

        $create->assertOk();
        $create->assertSeeText('Temel Kaynak Bilgileri');
        $create->assertSeeText('Profil ve Format');
        $create->assertSeeText('Bağlantı');
        $create->assertSeeText('Güncelleme Ayarları');
        $create->assertSeeText('Şifre, token ve header değerleri güvenli biçimde saklanır; ekranda düz metin olarak geri basılmaz.');

        $edit = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.product-data-hub.sources.edit', $source));

        $edit->assertOk();
        $edit->assertSeeText('Temel Kaynak Bilgileri');
        $edit->assertSeeText('Profil ve Format');
        $edit->assertSeeText('Bağlantı');
        $edit->assertSeeText('Gelişmiş Teknik Ayarlar');
        $edit->assertSeeText('Güncelleme Ayarları');
        $edit->assertSeeText('Şifre, token ve header değerleri düzenleme ekranında maskeli ve güvenli tutulur.');
    }

    /**
     * @return list<SupplierSource>
     */
    private function createManySources(int $count): array
    {
        $sources = [];

        for ($i = 1; $i <= $count; $i++) {
            $sequence = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $source = $this->createSource('PHM-' . $sequence, 'PH Source ' . $sequence, [
                'url' => sprintf('https://secret-%s.example.test/feed.xml?token=tok-%s', $sequence, $sequence),
                'config' => [
                    'profile_key' => 'AKDENIZ',
                    'source_profile_template' => 'AKDENIZ',
                    'format' => 'xml',
                    'product_node_path' => 'RECORD',
                ],
            ]);
            $this->insertPreviewLog($source, 'success');
            $this->addRequiredMappings($source);
            $sources[] = $source;
        }

        return $sources;
    }

    private function createSource(string $supplierCode, string $sourceName, array $overrides = []): SupplierSource
    {
        $supplier = Supplier::query()->create([
            'name' => 'PH Supplier ' . substr($supplierCode, -2),
            'code' => $supplierCode,
            'status' => 'active',
        ]);

        return SupplierSource::query()->create(array_replace_recursive([
            'supplier_id' => $supplier->id,
            'source_type' => 'xml',
            'source_name' => $sourceName,
            'status' => 'active',
            'url' => null,
            'config' => [
                'profile_key' => 'CUSTOM',
                'source_profile_template' => 'CUSTOM',
                'format' => 'xml',
            ],
        ], $overrides));
    }

    private function insertPreviewLog(SupplierSource $source, string $previewMode): void
    {
        DB::table('feed_sync_logs')->insert([
            'supplier_id' => $source->supplier_id,
            'supplier_source_id' => $source->id,
            'sync_type' => 'manual',
            'status' => $previewMode === 'success' ? 'completed' : 'failed',
            'total_records' => 1,
            'processed_records' => $previewMode === 'success' ? 1 : 0,
            'error_records' => $previewMode === 'success' ? 0 : 1,
            'started_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinute(),
            'error_summary' => $previewMode === 'success' ? 'Preview success' : 'Preview failed',
            'sync_metadata' => json_encode([
                'preview_mode' => $previewMode,
                'source_mode' => $previewMode === 'success' ? 'live_source' : 'error',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
    }

    private function addRequiredMappings(SupplierSource $source): void
    {
        foreach ([
            ['source_field' => 'urun_kodu', 'target_field' => 'supplier_product_code'],
            ['source_field' => 'urun_adi', 'target_field' => 'product_name'],
            ['source_field' => 'listefiyati', 'target_field' => 'purchase_price'],
        ] as $mapping) {
            SupplierFieldMapping::query()->create([
                'supplier_id' => $source->supplier_id,
                'supplier_source_id' => $source->id,
                'source_field' => $mapping['source_field'],
                'target_field' => $mapping['target_field'],
                'field_type' => 'text',
                'mapping_status' => 'mapped',
                'is_required' => true,
            ]);
        }
    }

    private function makeAkdenizFixtureSource(): SupplierSource
    {
        $source = $this->createSource('PH1B1-AKDENIZ', 'Akdeniz Preview Kaynağı');

        $fixturesDir = storage_path('framework/testing/product-data-hub-preview');
        if (!is_dir($fixturesDir)) {
            mkdir($fixturesDir, 0777, true);
        }

        $filePath = $fixturesDir . DIRECTORY_SEPARATOR . 'ph1b1-source-' . $source->id . '.xml';
        file_put_contents($filePath, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ROOT>
    <RECORD>
        <urun_id>9001</urun_id>
        <urunkodu>PB-4007</urunkodu>
        <urunattr_id>PB-4007-1</urunattr_id>
        <urunattrgr>PB-4007</urunattrgr>
        <urunattradi>PB-4007 Siyah</urunattradi>
        <urunadi>Powerbank</urunadi>
        <listefiyati>986.00</listefiyati>
        <listefiyatkapali>986.00</listefiyatkapali>
        <iskonto>0.45</iskonto>
        <netfiyat>542.30</netfiyat>
        <kur>TL</kur>
        <kdvorani>20</kdvorani>
        <stokmiktar>15</stokmiktar>
        <stokresim>https://example.test/pb-main.jpg</stokresim>
        <urunresim>https://example.test/pb-gallery.jpg</urunresim>
        <kategori>Powerbank</kategori>
    </RECORD>
</ROOT>
XML);

        $config = $source->config ?? [];
        $config['format'] = 'xml';
        $config['product_node_path'] = 'RECORD';
        $config['source_file_path'] = $filePath;
        $config['profile_key'] = 'AKDENIZ';
        $source->forceFill([
            'url' => null,
            'config' => $config,
        ])->save();

        return $source;
    }
}
