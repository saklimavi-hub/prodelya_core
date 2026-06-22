<?php

namespace Database\Seeders;

use App\Models\StandardPrintType;
use Illuminate\Database\Seeder;

class StandardPrintTypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'UV_PRINT', 'name' => 'UV Baskı', 'production_family' => 'promotion_print', 'default_requires_graphic' => true, 'default_requires_production' => true, 'default_requires_setup' => false, 'default_setup_types' => [], 'default_production_mode' => StandardPrintType::MODE_BOTH, 'sort_order' => 10],
            ['code' => 'LASER_PRINT', 'name' => 'Lazer Baskı', 'production_family' => 'laser', 'default_requires_graphic' => true, 'default_requires_production' => true, 'default_requires_setup' => false, 'default_setup_types' => [], 'default_production_mode' => StandardPrintType::MODE_INTERNAL, 'sort_order' => 20],
            ['code' => 'SCREEN_PRINT', 'name' => 'Serigrafi', 'production_family' => 'promotion_print', 'default_requires_graphic' => true, 'default_requires_production' => true, 'default_requires_setup' => true, 'default_setup_types' => ['film', 'montage'], 'default_production_mode' => StandardPrintType::MODE_BOTH, 'sort_order' => 30],
            ['code' => 'PAD_PRINT', 'name' => 'Tampon Baskı', 'production_family' => 'promotion_print', 'default_requires_graphic' => true, 'default_requires_production' => true, 'default_requires_setup' => false, 'default_setup_types' => [], 'default_production_mode' => StandardPrintType::MODE_BOTH, 'sort_order' => 40],
            ['code' => 'HOT_STAMPING', 'name' => 'Sıcak Baskı', 'production_family' => 'finishing', 'default_requires_graphic' => true, 'default_requires_production' => true, 'default_requires_setup' => true, 'default_setup_types' => ['cliche'], 'default_production_mode' => StandardPrintType::MODE_OUTSOURCED, 'sort_order' => 50],
            ['code' => 'DTF', 'name' => 'DTF', 'production_family' => 'textile', 'default_requires_graphic' => true, 'default_requires_production' => true, 'default_requires_setup' => false, 'default_setup_types' => [], 'default_production_mode' => StandardPrintType::MODE_BOTH, 'sort_order' => 60],
            ['code' => 'TRANSFER_PRINT', 'name' => 'Transfer Baskı', 'production_family' => 'textile', 'default_requires_graphic' => true, 'default_requires_production' => true, 'default_requires_setup' => false, 'default_setup_types' => [], 'default_production_mode' => StandardPrintType::MODE_BOTH, 'sort_order' => 70],
            ['code' => 'DIGITAL_PRINT', 'name' => 'Dijital Baskı', 'production_family' => 'digital_print', 'default_requires_graphic' => true, 'default_requires_production' => true, 'default_requires_setup' => false, 'default_setup_types' => [], 'default_production_mode' => StandardPrintType::MODE_INTERNAL, 'sort_order' => 80],
            ['code' => 'OFFSET_PRINT', 'name' => 'Ofset Baskı', 'production_family' => 'offset_print', 'default_requires_graphic' => true, 'default_requires_production' => true, 'default_requires_setup' => true, 'default_setup_types' => ['film', 'montage'], 'default_production_mode' => StandardPrintType::MODE_OUTSOURCED, 'sort_order' => 90],
            ['code' => 'SUBLIMATION', 'name' => 'Sublimasyon', 'production_family' => 'textile', 'default_requires_graphic' => true, 'default_requires_production' => true, 'default_requires_setup' => false, 'default_setup_types' => [], 'default_production_mode' => StandardPrintType::MODE_BOTH, 'sort_order' => 100],
            ['code' => 'EMBROIDERY', 'name' => 'Nakış', 'production_family' => 'textile', 'default_requires_graphic' => true, 'default_requires_production' => true, 'default_requires_setup' => true, 'default_setup_types' => ['stencil'], 'default_production_mode' => StandardPrintType::MODE_OUTSOURCED, 'sort_order' => 110],
            ['code' => 'EMBOSSING', 'name' => 'Kabartma', 'production_family' => 'finishing', 'default_requires_graphic' => true, 'default_requires_production' => true, 'default_requires_setup' => true, 'default_setup_types' => ['mold'], 'default_production_mode' => StandardPrintType::MODE_OUTSOURCED, 'sort_order' => 120],
            ['code' => 'GOFRE', 'name' => 'Gofre', 'production_family' => 'finishing', 'default_requires_graphic' => true, 'default_requires_production' => true, 'default_requires_setup' => true, 'default_setup_types' => ['mold'], 'default_production_mode' => StandardPrintType::MODE_OUTSOURCED, 'sort_order' => 130],
            ['code' => 'FOIL', 'name' => 'Varak', 'production_family' => 'finishing', 'default_requires_graphic' => true, 'default_requires_production' => true, 'default_requires_setup' => true, 'default_setup_types' => ['foil_mold'], 'default_production_mode' => StandardPrintType::MODE_OUTSOURCED, 'sort_order' => 140],
            ['code' => 'CUTTING', 'name' => 'Kesim', 'production_family' => 'finishing', 'default_requires_graphic' => false, 'default_requires_production' => true, 'default_requires_setup' => false, 'default_setup_types' => [], 'default_production_mode' => StandardPrintType::MODE_BOTH, 'sort_order' => 150],
        ];

        foreach ($rows as $row) {
            StandardPrintType::query()->updateOrCreate(
                ['code' => $row['code']],
                array_merge($row, ['status' => StandardPrintType::STATUS_ACTIVE])
            );
        }
    }
}
