<?php

namespace Database\Seeders;

use App\Models\StandardCategory;
use App\Models\Supplier;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use App\Models\TenantSupplierAccess;
use Illuminate\Database\Seeder;

class DefaultSupplierCategoryMappingSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = TenantAccount::query()->where('panel_subdomain', 'demo')->first();

        if (!$tenant) {
            return;
        }

        $supplierRows = [
            [
                'supplier' => ['code' => 'YENI-NESIL', 'name' => 'Yeni Nesil', 'status' => 'active'],
                'source' => ['source_name' => 'Yeni Nesil CSV', 'source_type' => 'csv', 'status' => 'active'],
                'access' => ['is_active' => true, 'can_view_products' => true],
                'mappings' => [
                    ['source_category' => 'Yazi Gerecleri', 'standard_code' => 'PROMO-KALEM', 'target_category' => 'Promosyon Ürünleri / Kalemler', 'mapping_status' => 'mapped', 'confidence_score' => 96],
                    ['source_category' => 'Termos Matara', 'standard_code' => 'PROMO-TERMOS', 'target_category' => 'Promosyon Ürünleri / Termos / Matara', 'mapping_status' => 'mapped', 'confidence_score' => 92],
                ],
            ],
            [
                'supplier' => ['code' => 'ETKIN', 'name' => 'Etkin Promosyon', 'status' => 'active'],
                'source' => ['source_name' => 'Etkin Promosyon XML', 'source_type' => 'xml', 'status' => 'active'],
                'access' => ['is_active' => true, 'can_view_products' => true],
                'mappings' => [
                    ['source_category' => 'Canta Grubu', 'standard_code' => 'PROMO-CANTA', 'target_category' => 'Promosyon Ürünleri / Çantalar', 'mapping_status' => 'mapped', 'confidence_score' => 88],
                ],
            ],
            [
                'supplier' => ['code' => 'AKDENIZ', 'name' => 'Akdeniz Promosyon', 'status' => 'active'],
                'source' => ['source_name' => 'Akdeniz Promosyon API', 'source_type' => 'api', 'status' => 'active'],
                'access' => ['is_active' => true, 'can_view_products' => true],
                'mappings' => [
                    ['source_category' => 'Kurumsal Setler', 'standard_code' => null, 'target_category' => 'Promosyon Ürünleri / Kalemler', 'mapping_status' => 'pending', 'confidence_score' => 48],
                ],
            ],
            [
                'supplier' => ['code' => 'ILPEN', 'name' => 'İlpen', 'status' => 'active'],
                'source' => ['source_name' => 'İlpen XML', 'source_type' => 'xml', 'status' => 'active'],
                'access' => ['is_active' => true, 'can_view_products' => true],
                'mappings' => [
                    ['source_category' => 'Termos / Matara', 'standard_code' => 'PROMO-TERMOS', 'target_category' => 'Promosyon Ürünleri / Termos / Matara', 'mapping_status' => 'mapped', 'confidence_score' => 89],
                ],
            ],
        ];

        foreach ($supplierRows as $row) {
            $supplier = Supplier::query()->updateOrCreate(
                ['code' => $row['supplier']['code']],
                $row['supplier']
            );

            $source = SupplierSource::query()->updateOrCreate(
                ['supplier_id' => $supplier->id, 'source_name' => $row['source']['source_name']],
                $row['source'] + ['supplier_id' => $supplier->id]
            );

            TenantSupplierAccess::query()->updateOrCreate(
                ['tenant_account_id' => $tenant->id, 'supplier_id' => $supplier->id],
                [
                    'is_active' => $row['access']['is_active'],
                    'can_view_products' => $row['access']['can_view_products'],
                    'can_request_purchase' => true,
                    'can_use_in_quotes' => true,
                    'visible_in_catalog' => true,
                    'export_allowed' => false,
                    'granted_at' => now(),
                ]
            );

            foreach ($row['mappings'] as $mappingRow) {
                $standardCategory = $mappingRow['standard_code']
                    ? StandardCategory::query()->where('code', $mappingRow['standard_code'])->first()
                    : null;

                SupplierCategoryMapping::query()->updateOrCreate(
                    [
                        'supplier_source_id' => $source->id,
                        'source_category' => $mappingRow['source_category'],
                    ],
                    [
                        'supplier_id' => $supplier->id,
                        'standard_category_id' => $standardCategory?->id,
                        'target_category' => $mappingRow['target_category'],
                        'mapping_status' => $mappingRow['mapping_status'],
                        'confidence_score' => $mappingRow['confidence_score'],
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
