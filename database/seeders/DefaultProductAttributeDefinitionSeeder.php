<?php

namespace Database\Seeders;

use App\Models\ProductAttributeDefinition;
use Illuminate\Database\Seeder;

class DefaultProductAttributeDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            ['code' => 'color', 'name' => 'Renk', 'type' => 'select'],
            ['code' => 'material', 'name' => 'Malzeme', 'type' => 'select'],
            ['code' => 'size', 'name' => 'Ebat / Ölçü', 'type' => 'text'],
            ['code' => 'volume_ml', 'name' => 'Hacim', 'type' => 'number', 'unit' => 'ml'],
            ['code' => 'capacity_mah', 'name' => 'Kapasite', 'type' => 'number', 'unit' => 'mAh'],
            ['code' => 'capacity_gb', 'name' => 'Kapasite', 'type' => 'number', 'unit' => 'GB'],
            ['code' => 'print_type', 'name' => 'Baskı Türü', 'type' => 'multi'],
            ['code' => 'print_area', 'name' => 'Baskı Alanı', 'type' => 'text'],
            ['code' => 'touch_pen', 'name' => 'Dokunmatik', 'type' => 'boolean'],
            ['code' => 'mechanism', 'name' => 'Mekanizma', 'type' => 'select'],
            ['code' => 'ink_color', 'name' => 'Mürekkep Rengi', 'type' => 'select'],
            ['code' => 'paper_size', 'name' => 'Kağıt Ebatı', 'type' => 'select'],
            ['code' => 'paper_weight', 'name' => 'Kağıt Gramajı', 'type' => 'number', 'unit' => 'gr'],
            ['code' => 'cover_type', 'name' => 'Kapak Tipi', 'type' => 'select'],
            ['code' => 'page_count', 'name' => 'Sayfa / Yaprak Sayısı', 'type' => 'number'],
            ['code' => 'binding_type', 'name' => 'Cilt Tipi', 'type' => 'select'],
            ['code' => 'lamination', 'name' => 'Selefon', 'type' => 'select'],
            ['code' => 'cutting_type', 'name' => 'Kesim Türü', 'type' => 'select'],
            ['code' => 'print_side', 'name' => 'Baskı Yüzü', 'type' => 'select'],
        ];

        foreach ($definitions as $index => $definition) {
            ProductAttributeDefinition::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'type' => $definition['type'],
                    'unit' => $definition['unit'] ?? null,
                    'is_filterable' => true,
                    'is_required' => false,
                    'sort_order' => ($index + 1) * 10,
                    'is_active' => true,
                    'meta' => ['seeded' => true],
                ]
            );
        }
    }
}
