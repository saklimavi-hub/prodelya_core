<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_products_raw', function (Blueprint $table) {
            if (!Schema::hasColumn('supplier_products_raw', 'standard_product_id')) {
                $table->foreignId('standard_product_id')->nullable()->after('standard_category_id')->constrained('standard_products')->nullOnDelete();
            }
        });

        Schema::table('supplier_product_variants_raw', function (Blueprint $table) {
            if (!Schema::hasColumn('supplier_product_variants_raw', 'standard_product_variant_id')) {
                $table->foreignId('standard_product_variant_id')->nullable()->after('supplier_product_raw_id')->constrained('standard_product_variants')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplier_products_raw', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_products_raw', 'standard_product_id')) {
                $table->dropConstrainedForeignId('standard_product_id');
            }
        });

        Schema::table('supplier_product_variants_raw', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_product_variants_raw', 'standard_product_variant_id')) {
                $table->dropConstrainedForeignId('standard_product_variant_id');
            }
        });
    }
};
