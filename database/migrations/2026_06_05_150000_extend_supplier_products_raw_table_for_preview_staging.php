<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_products_raw', function (Blueprint $table) {
            if (!Schema::hasColumn('supplier_products_raw', 'tenant_account_id')) {
                $table->foreignId('tenant_account_id')->nullable()->after('id')->constrained('tenant_accounts')->nullOnDelete();
            }

            if (!Schema::hasColumn('supplier_products_raw', 'supplier_product_id')) {
                $table->string('supplier_product_id')->nullable()->after('supplier_source_id');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'supplier_product_code')) {
                $table->string('supplier_product_code')->nullable()->after('supplier_product_id');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'supplier_group_code')) {
                $table->string('supplier_group_code')->nullable()->after('supplier_product_code');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'product_name')) {
                $table->string('product_name')->nullable()->after('supplier_group_code');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'supplier_category_name')) {
                $table->string('supplier_category_name')->nullable()->after('product_name');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'standard_category_id')) {
                $table->foreignId('standard_category_id')->nullable()->after('supplier_category_name')->constrained('standard_categories')->nullOnDelete();
            }

            if (!Schema::hasColumn('supplier_products_raw', 'stock_quantity')) {
                $table->decimal('stock_quantity', 15, 4)->nullable()->after('standard_category_id');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'purchase_price')) {
                $table->decimal('purchase_price', 15, 4)->nullable()->after('stock_quantity');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'currency')) {
                $table->string('currency', 10)->nullable()->after('purchase_price');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'vat_rate')) {
                $table->decimal('vat_rate', 10, 2)->nullable()->after('currency');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'image_url')) {
                $table->text('image_url')->nullable()->after('vat_rate');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'product_url')) {
                $table->text('product_url')->nullable()->after('image_url');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'detail_url')) {
                $table->text('detail_url')->nullable()->after('product_url');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'color')) {
                $table->string('color')->nullable()->after('detail_url');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'size')) {
                $table->string('size')->nullable()->after('color');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'measure')) {
                $table->string('measure')->nullable()->after('size');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'description')) {
                $table->text('description')->nullable()->after('measure');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'warning_flag')) {
                $table->boolean('warning_flag')->nullable()->after('description');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'raw_payload')) {
                $table->json('raw_payload')->nullable()->after('warning_flag');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'normalized_payload')) {
                $table->json('normalized_payload')->nullable()->after('raw_payload');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'import_hash')) {
                $table->string('import_hash', 64)->nullable()->after('normalized_payload');
                $table->index('import_hash');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'mapping_status')) {
                $table->string('mapping_status', 40)->nullable()->after('import_hash');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'warnings')) {
                $table->json('warnings')->nullable()->after('mapping_status');
            }

            if (!Schema::hasColumn('supplier_products_raw', 'errors')) {
                $table->json('errors')->nullable()->after('warnings');
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplier_products_raw', function (Blueprint $table) {
            foreach ([
                'tenant_account_id',
                'supplier_product_id',
                'supplier_product_code',
                'supplier_group_code',
                'product_name',
                'supplier_category_name',
                'standard_category_id',
                'stock_quantity',
                'purchase_price',
                'currency',
                'vat_rate',
                'image_url',
                'product_url',
                'detail_url',
                'color',
                'size',
                'measure',
                'description',
                'warning_flag',
                'raw_payload',
                'normalized_payload',
                'import_hash',
                'mapping_status',
                'warnings',
                'errors',
            ] as $column) {
                if (Schema::hasColumn('supplier_products_raw', $column)) {
                    if (in_array($column, ['tenant_account_id', 'standard_category_id'], true)) {
                        $table->dropConstrainedForeignId($column);
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });
    }
};
