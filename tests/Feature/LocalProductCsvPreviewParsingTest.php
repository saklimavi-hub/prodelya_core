<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsLocalProductSourceFixtures;
use Tests\TestCase;

class LocalProductCsvPreviewParsingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLocalProductSourceFixtures;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLocalProductSourceFixtures();
    }

    public function test_preview_parses_d1_smoke_csv_without_writes(): void
    {
        $csv = implode("\n", [
            'product_type,group_code,product_name,variant_sku,variant_name,color,size,category,list_price,currency,initial_stock,quote_visible,status',
            'flat,,D1 Smoke CSV Flat,D1-SMOKE-CSV-FLAT,,,,,45.50,TRY,1,1,active',
            'variant,D1-SMOKE-CSV-KALEM,D1 Smoke CSV Kalem,D1-SMOKE-CSV-KALEM-MAVI,Mavi,Mavi,,,12.00,TRY,2,1,active',
            'variant,D1-SMOKE-CSV-KALEM,D1 Smoke CSV Kalem,D1-SMOKE-CSV-KALEM-KIRMIZI,Kırmızı,Kırmızı,,,13.00,TRY,3,1,active',
        ]);
        $file = UploadedFile::fake()->createWithContent('d1-smoke.csv', $csv);

        $before = $this->tableCounts();

        $response = $this->postOnCentralHost('/admin/catalog/local-products/import/preview', ['file' => $file]);

        $response->assertRedirect('/admin/catalog/local-products/import');
        $response->assertSessionHas('local_product_import_preview', function (array $preview): bool {
            return ($preview['delimiter'] ?? null) === ','
                && ($preview['total'] ?? null) === 3
                && empty($preview['errors'] ?? [])
                && count($preview['preview_rows'] ?? []) === 3;
        });

        $this->assertSame($before, $this->tableCounts());
    }

    public function test_preview_handles_utf8_bom_semicolon_and_blank_rows(): void
    {
        $csv = "\xEF\xBB\xBFurun_kodu;urun_adi;stok;liste_fiyati;para_birimi;teklifte_kullanilsin;aktif\n"
            . "SEM-001;Semicolon Ürün;5;18,50;TRY;1;1\n"
            . "   ; ; ; ; ; ; \n"
            . "SEM-002;İkinci Ürün;7;22,75;TRY;1;1\n";
        $file = UploadedFile::fake()->createWithContent('semicolon.csv', $csv);

        $response = $this->postOnCentralHost('/admin/catalog/local-products/import/preview', ['file' => $file]);

        $response->assertRedirect('/admin/catalog/local-products/import');
        $response->assertSessionHas('local_product_import_preview', function (array $preview): bool {
            return ($preview['delimiter'] ?? null) === ';'
                && ($preview['total'] ?? null) === 2
                && empty($preview['errors'] ?? [])
                && ($preview['preview_rows'][0]['product_code'] ?? null) === 'SEM-001';
        });
    }

    public function test_preview_handles_quoted_comma_without_splitting_product_name(): void
    {
        $csv = implode("\n", [
            'urun_kodu,urun_adi,stok,liste_fiyati,para_birimi,teklifte_kullanilsin,aktif',
            'Q-001,"D1 Kupa, Büyük",2,19.90,TRY,1,1',
        ]);
        $file = UploadedFile::fake()->createWithContent('quoted-comma.csv', $csv);

        $response = $this->postOnCentralHost('/admin/catalog/local-products/import/preview', ['file' => $file]);

        $response->assertRedirect('/admin/catalog/local-products/import');
        $response->assertSessionHas('local_product_import_preview', function (array $preview): bool {
            return ($preview['total'] ?? null) === 1
                && ($preview['preview_rows'][0]['product_name'] ?? null) === 'D1 Kupa, Büyük'
                && empty($preview['errors'] ?? []);
        });
    }

    public function test_preview_rejects_extra_non_empty_columns_without_500_or_writes(): void
    {
        $csv = implode("\n", [
            'urun_kodu,urun_adi,stok',
            'EXTRA-001,Fazla Kolonlu Ürün,2,FAZLA',
        ]);
        $file = UploadedFile::fake()->createWithContent('extra-column.csv', $csv);

        $before = $this->tableCounts();

        $response = $this->postOnCentralHost('/admin/catalog/local-products/import/preview', ['file' => $file]);

        $response->assertRedirect('/admin/catalog/local-products/import');
        $response->assertSessionHas('local_product_import_preview', function (array $preview): bool {
            return ($preview['total'] ?? null) === 1
                && str_contains(($preview['errors'][0] ?? ''), 'Fazla sütunları kontrol edin')
                && str_contains(($preview['preview_rows'][0]['errors'][0] ?? ''), 'Fazla sütunları kontrol edin');
        });

        $view = $this->getOnCentralHost('/admin/catalog/local-products/import');
        $view->assertOk();
        $view->assertSeeText('Hatalı satırlar çözülmeden explicit apply açılamaz.');

        $this->assertSame($before, $this->tableCounts());
    }

    public function test_preview_trims_empty_trailing_columns_and_pads_missing_cells(): void
    {
        $csv = implode("\n", [
            'urun_kodu,urun_adi,stok,liste_fiyati,para_birimi,teklifte_kullanilsin,aktif',
            'TRIM-001,Trailing Empty,3,12.50,TRY,1,1,,',
            'PAD-001,Padded Product,4',
        ]);
        $file = UploadedFile::fake()->createWithContent('trim-pad.csv', $csv);

        $response = $this->postOnCentralHost('/admin/catalog/local-products/import/preview', ['file' => $file]);

        $response->assertRedirect('/admin/catalog/local-products/import');
        $response->assertSessionHas('local_product_import_preview', function (array $preview): bool {
            return ($preview['total'] ?? null) === 2
                && empty($preview['errors'] ?? [])
                && ($preview['preview_rows'][1]['product_code'] ?? null) === 'PAD-001';
        });
    }

    public function test_apply_uses_same_parsed_rows_after_preview(): void
    {
        $csv = implode("\n", [
            'urun_kodu;urun_adi;stok;liste_fiyati;para_birimi;teklifte_kullanilsin;aktif',
            'APPLY-001;Apply Ürün;6;33,40;TRY;1;1',
        ]);
        $file = UploadedFile::fake()->createWithContent('apply.csv', $csv);

        $this->postOnCentralHost('/admin/catalog/local-products/import/preview', ['file' => $file])
            ->assertRedirect('/admin/catalog/local-products/import');

        $this->postOnCentralHost('/admin/catalog/local-products/import', ['duplicate_policy' => 'update'])
            ->assertRedirect('/admin/catalog/local-products');

        $this->assertDatabaseHas('tenant_catalog_products', [
            'tenant_account_id' => $this->tenant->id,
            'product_code' => 'APPLY-001',
            'catalog_source' => 'local_product',
        ]);
    }

    private function tableCounts(): array
    {
        return [
            'products' => DB::table('tenant_catalog_products')->count(),
            'variants' => DB::table('tenant_catalog_product_variants')->count(),
            'stocks' => DB::table('tenant_local_stocks')->count(),
            'movements' => DB::table('stock_movements')->count(),
        ];
    }
}
