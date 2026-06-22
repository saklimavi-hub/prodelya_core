<?php

namespace Database\Seeders;

use App\Models\SupplierFieldMapping;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Services\ProductFieldDictionaryService;
use Illuminate\Database\Seeder;

class DefaultSupplierFieldMappingSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = TenantAccount::query()->where('panel_subdomain', 'demo')->first();

        if (!$tenant) {
            return;
        }

        $dictionary = app(ProductFieldDictionaryService::class);

        $mappingSets = [
            'ETKIN' => [
                'urun_kodu' => 'supplier_product_code',
                'urun_grupkodu' => 'supplier_group_code',
                'urun_adi' => 'product_name',
                'urun_resim' => 'image_url',
                'urun_stok' => 'stock_quantity',
                'urun_fiyat' => 'purchase_price',
                'urun_kirmizi' => 'warning_flag',
            ],
            'AKDENIZ' => [
                'urun_id' => 'parent_supplier_product_id',
                'urunkodu' => 'supplier_product_code',
                'urunattr_id' => 'variant_id',
                'urunattradi' => 'variant_name',
                'stokresim' => 'variant_image_url',
                'kategori' => 'supplier_category_name',
                'netfiyat' => 'purchase_price',
            ],
            'YENI-NESIL' => [
                'uid' => 'supplier_product_id',
                'kod' => 'supplier_product_code',
                'kodgrup' => 'supplier_group_code',
                'renk' => 'variant_color',
                'turuncu' => 'warning_flag',
                'fiyat' => 'purchase_price',
            ],
            'ILPEN' => [
                'UrunKartiID' => 'parent_supplier_product_id',
                'UrunAdi' => 'product_name',
                'UrunGrupKodu' => 'supplier_group_code',
                'ResimUrl' => 'parent_image_url',
                'KategoriMain' => 'supplier_category_name',
                'StokKodu' => 'variant_stock_code',
                'VaryasyonID' => 'variant_id',
                'VaryasyonResim' => 'variant_image_url',
            ],
        ];

        foreach ($mappingSets as $supplierCode => $rows) {
            $source = SupplierSource::query()
                ->with('supplier')
                ->whereHas('supplier', fn ($query) => $query->where('code', $supplierCode))
                ->first();

            if (!$source) {
                continue;
            }

            $standardFields = $dictionary->getStandardFields();

            foreach ($rows as $sourceField => $targetField) {
                SupplierFieldMapping::query()->updateOrCreate(
                    [
                        'tenant_account_id' => $tenant->id,
                        'supplier_id' => $source->supplier_id,
                        'supplier_source_id' => $source->id,
                        'source_field' => $sourceField,
                    ],
                    [
                        'legacy_field_name' => $dictionary->normalizeSourceFieldWithoutAlias($sourceField),
                        'target_field' => $targetField,
                        'field_type' => $standardFields[$targetField]['type'] ?? 'text',
                        'mapping_status' => 'mapped',
                        'confidence_score' => 96,
                        'is_required' => (bool) ($standardFields[$targetField]['required'] ?? false),
                        'reviewed_at' => now(),
                        'meta' => ['seeded' => true],
                    ]
                );
            }
        }
    }
}
