<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standard_products', function (Blueprint $table) {
            if (!Schema::hasColumn('standard_products', 'tenant_account_id')) {
                $table->foreignId('tenant_account_id')->nullable()->after('id')->constrained('tenant_accounts')->nullOnDelete();
            }

            if (!Schema::hasColumn('standard_products', 'supplier_id')) {
                $table->foreignId('supplier_id')->nullable()->after('tenant_account_id')->constrained()->nullOnDelete();
            }

            if (!Schema::hasColumn('standard_products', 'supplier_product_raw_id')) {
                $table->foreignId('supplier_product_raw_id')->nullable()->after('supplier_id')->constrained('supplier_products_raw')->nullOnDelete();
            }

            if (!Schema::hasColumn('standard_products', 'standard_product_code')) {
                $table->string('standard_product_code')->nullable()->after('supplier_product_raw_id');
                $table->unique('standard_product_code');
            }

            if (!Schema::hasColumn('standard_products', 'product_name')) {
                $table->string('product_name')->nullable()->after('standard_product_code');
            }

            if (!Schema::hasColumn('standard_products', 'base_product_name')) {
                $table->string('base_product_name')->nullable()->after('product_name');
            }

            if (!Schema::hasColumn('standard_products', 'slug')) {
                $table->string('slug')->nullable()->after('base_product_name');
            }

            if (!Schema::hasColumn('standard_products', 'standard_category_id')) {
                $table->foreignId('standard_category_id')->nullable()->after('slug')->constrained('standard_categories')->nullOnDelete();
            }

            if (!Schema::hasColumn('standard_products', 'product_family')) {
                $table->string('product_family')->default('promotion')->after('standard_category_id');
            }

            if (!Schema::hasColumn('standard_products', 'image_url')) {
                $table->text('image_url')->nullable()->after('product_family');
            }

            if (!Schema::hasColumn('standard_products', 'product_url')) {
                $table->text('product_url')->nullable()->after('image_url');
            }

            if (!Schema::hasColumn('standard_products', 'vat_rate')) {
                $table->decimal('vat_rate', 10, 2)->nullable()->after('product_url');
            }

            if (!Schema::hasColumn('standard_products', 'currency')) {
                $table->string('currency', 10)->nullable()->after('vat_rate');
            }

            if (!Schema::hasColumn('standard_products', 'min_purchase_price')) {
                $table->decimal('min_purchase_price', 15, 4)->nullable()->after('currency');
            }

            if (!Schema::hasColumn('standard_products', 'max_purchase_price')) {
                $table->decimal('max_purchase_price', 15, 4)->nullable()->after('min_purchase_price');
            }

            if (!Schema::hasColumn('standard_products', 'total_stock_quantity')) {
                $table->decimal('total_stock_quantity', 15, 4)->nullable()->after('max_purchase_price');
            }

            if (!Schema::hasColumn('standard_products', 'supplier_count')) {
                $table->integer('supplier_count')->default(0)->after('total_stock_quantity');
            }

            if (!Schema::hasColumn('standard_products', 'variant_count')) {
                $table->integer('variant_count')->default(0)->after('supplier_count');
            }

            if (!Schema::hasColumn('standard_products', 'warning_flag')) {
                $table->boolean('warning_flag')->default(false)->after('variant_count');
            }

            if (!Schema::hasColumn('standard_products', 'visible_in_catalog')) {
                $table->boolean('visible_in_catalog')->default(true)->after('is_active');
            }

            if (!Schema::hasColumn('standard_products', 'source_summary')) {
                $table->json('source_summary')->nullable()->after('visible_in_catalog');
            }

            if (!Schema::hasColumn('standard_products', 'meta')) {
                $table->json('meta')->nullable()->after('source_summary');
            }
        });
    }

    public function down(): void
    {
        Schema::table('standard_products', function (Blueprint $table) {
            foreach ([
                'tenant_account_id',
                'supplier_id',
                'supplier_product_raw_id',
                'standard_product_code',
                'product_name',
                'base_product_name',
                'slug',
                'standard_category_id',
                'product_family',
                'image_url',
                'product_url',
                'vat_rate',
                'currency',
                'min_purchase_price',
                'max_purchase_price',
                'total_stock_quantity',
                'supplier_count',
                'variant_count',
                'warning_flag',
                'visible_in_catalog',
                'source_summary',
                'meta',
            ] as $column) {
                if (Schema::hasColumn('standard_products', $column)) {
                    if (in_array($column, ['tenant_account_id', 'supplier_id', 'supplier_product_raw_id', 'standard_category_id'], true)) {
                        $table->dropConstrainedForeignId($column);
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });
    }
};
