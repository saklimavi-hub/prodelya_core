<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'tenant_catalog_product_id')) {
                $table->foreignId('tenant_catalog_product_id')->nullable()->constrained('tenant_catalog_products')->nullOnDelete()->after('order_id');
            }

            if (!Schema::hasColumn('order_items', 'tenant_catalog_product_variant_id')) {
                $table->foreignId('tenant_catalog_product_variant_id')->nullable()->constrained('tenant_catalog_product_variants')->nullOnDelete()->after('tenant_catalog_product_id');
            }

            if (!Schema::hasColumn('order_items', 'standard_product_id')) {
                $table->foreignId('standard_product_id')->nullable()->constrained('standard_products')->nullOnDelete()->after('tenant_catalog_product_variant_id');
            }

            if (!Schema::hasColumn('order_items', 'standard_product_variant_id')) {
                $table->foreignId('standard_product_variant_id')->nullable()->constrained('standard_product_variants')->nullOnDelete()->after('standard_product_id');
            }

            if (!Schema::hasColumn('order_items', 'supplier_source_id')) {
                $table->foreignId('supplier_source_id')->nullable()->constrained('supplier_sources')->nullOnDelete()->after('supplier_id');
            }

            if (!Schema::hasColumn('order_items', 'product_snapshot')) {
                $table->json('product_snapshot')->nullable()->after('description');
            }

            if (!Schema::hasColumn('order_items', 'price_snapshot')) {
                $table->json('price_snapshot')->nullable()->after('product_snapshot');
            }

            if (!Schema::hasColumn('order_items', 'stock_snapshot')) {
                $table->json('stock_snapshot')->nullable()->after('price_snapshot');
            }

            if (!Schema::hasColumn('order_items', 'catalog_source')) {
                $table->string('catalog_source')->nullable()->after('stock_snapshot');
            }
        });

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "ALTER TABLE order_items MODIFY product_source ENUM('local_stock','supplier_feed','customer_supplied','manual','tenant_catalog') NULL"
            );
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "ALTER TABLE order_items MODIFY product_source ENUM('local_stock','supplier_feed','customer_supplied','manual') NULL"
            );
        }

        Schema::table('order_items', function (Blueprint $table) {
            foreach ([
                'tenant_catalog_product_id',
                'tenant_catalog_product_variant_id',
                'standard_product_id',
                'standard_product_variant_id',
                'supplier_source_id',
            ] as $foreignColumn) {
                if (Schema::hasColumn('order_items', $foreignColumn)) {
                    $table->dropConstrainedForeignId($foreignColumn);
                }
            }

            foreach (['product_snapshot', 'price_snapshot', 'stock_snapshot', 'catalog_source'] as $column) {
                if (Schema::hasColumn('order_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
