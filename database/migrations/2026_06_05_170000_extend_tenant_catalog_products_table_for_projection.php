<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_catalog_products', function (Blueprint $table) {
            if (!Schema::hasColumn('tenant_catalog_products', 'product_code')) {
                $table->string('product_code')->nullable()->after('standard_product_id');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'product_name')) {
                $table->string('product_name')->nullable()->after('product_code');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'slug')) {
                $table->string('slug')->nullable()->after('product_name');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'standard_category_id')) {
                $table->foreignId('standard_category_id')->nullable()->after('slug')->constrained('standard_categories')->nullOnDelete();
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'product_family')) {
                $table->string('product_family')->nullable()->default('promotion')->after('standard_category_id');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'image_url')) {
                $table->text('image_url')->nullable()->after('product_family');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'display_price')) {
                $table->decimal('display_price', 15, 4)->nullable()->after('image_url');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'total_stock_quantity')) {
                $table->decimal('total_stock_quantity', 15, 4)->nullable()->after('currency');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'local_stock_quantity')) {
                $table->decimal('local_stock_quantity', 15, 4)->nullable()->after('total_stock_quantity');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'supplier_stock_quantity')) {
                $table->decimal('supplier_stock_quantity', 15, 4)->nullable()->after('local_stock_quantity');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'safe_stock_quantity')) {
                $table->integer('safe_stock_quantity')->nullable()->after('supplier_stock_quantity');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'price_multiplier')) {
                $table->decimal('price_multiplier', 10, 4)->nullable()->after('safe_stock_quantity');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'source_summary')) {
                $table->json('source_summary')->nullable()->after('price_multiplier');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'visible_in_catalog')) {
                $table->boolean('visible_in_catalog')->default(true)->after('source_summary');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'meta')) {
                $table->json('meta')->nullable()->after('visible_in_catalog');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_catalog_products', function (Blueprint $table) {
            foreach ([
                'product_code',
                'product_name',
                'slug',
                'standard_category_id',
                'product_family',
                'image_url',
                'display_price',
                'total_stock_quantity',
                'local_stock_quantity',
                'supplier_stock_quantity',
                'safe_stock_quantity',
                'price_multiplier',
                'source_summary',
                'visible_in_catalog',
                'meta',
            ] as $column) {
                if (Schema::hasColumn('tenant_catalog_products', $column)) {
                    if ($column === 'standard_category_id') {
                        $table->dropConstrainedForeignId($column);
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });
    }
};
