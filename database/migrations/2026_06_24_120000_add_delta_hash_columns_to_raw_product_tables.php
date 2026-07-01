<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_products_raw', function (Blueprint $table) {
            foreach ([
                'identity_hash',
                'content_hash',
                'price_hash',
                'stock_hash',
                'image_hash',
                'category_hash',
                'variant_structure_hash',
            ] as $column) {
                if (!Schema::hasColumn('supplier_products_raw', $column)) {
                    $table->string($column, 64)->nullable()->after('import_hash');
                }
            }
        });

        Schema::table('supplier_product_variants_raw', function (Blueprint $table) {
            foreach ([
                'identity_hash',
                'content_hash',
                'price_hash',
                'stock_hash',
                'image_hash',
                'category_hash',
            ] as $column) {
                if (!Schema::hasColumn('supplier_product_variants_raw', $column)) {
                    $table->string($column, 64)->nullable()->after('import_hash');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplier_products_raw', function (Blueprint $table) {
            foreach ([
                'identity_hash',
                'content_hash',
                'price_hash',
                'stock_hash',
                'image_hash',
                'category_hash',
                'variant_structure_hash',
            ] as $column) {
                if (Schema::hasColumn('supplier_products_raw', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('supplier_product_variants_raw', function (Blueprint $table) {
            foreach ([
                'identity_hash',
                'content_hash',
                'price_hash',
                'stock_hash',
                'image_hash',
                'category_hash',
            ] as $column) {
                if (Schema::hasColumn('supplier_product_variants_raw', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
