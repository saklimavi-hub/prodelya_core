<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_supplier_purchase_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_supplier_purchase_entries', 'tenant_catalog_product_variant_id')) {
                $table->unsignedBigInteger('tenant_catalog_product_variant_id')->nullable()->after('tenant_catalog_product_id');
            }

            if (! Schema::hasColumn('tenant_supplier_purchase_entries', 'stock_scope')) {
                $table->string('stock_scope', 20)->nullable()->after('tenant_catalog_product_variant_id');
            }

            if (! Schema::hasColumn('tenant_supplier_purchase_entries', 'entry_status')) {
                $table->string('entry_status', 30)->default('completed')->after('entry_type');
            }

            if (! Schema::hasColumn('tenant_supplier_purchase_entries', 'original_currency')) {
                $table->string('original_currency', 3)->nullable()->after('currency');
            }

            if (! Schema::hasColumn('tenant_supplier_purchase_entries', 'exchange_rate')) {
                $table->decimal('exchange_rate', 14, 6)->nullable()->after('original_currency');
            }

            if (! Schema::hasColumn('tenant_supplier_purchase_entries', 'exchange_rate_date')) {
                $table->date('exchange_rate_date')->nullable()->after('exchange_rate');
            }

            if (! Schema::hasColumn('tenant_supplier_purchase_entries', 'original_list_price')) {
                $table->decimal('original_list_price', 14, 4)->nullable()->after('exchange_rate_date');
            }

            if (! Schema::hasColumn('tenant_supplier_purchase_entries', 'calculated_unit_price_original')) {
                $table->decimal('calculated_unit_price_original', 14, 4)->nullable()->after('original_list_price');
            }

            if (! Schema::hasColumn('tenant_supplier_purchase_entries', 'final_unit_price_original')) {
                $table->decimal('final_unit_price_original', 14, 4)->nullable()->after('calculated_unit_price_original');
            }

            if (! Schema::hasColumn('tenant_supplier_purchase_entries', 'final_unit_price_try')) {
                $table->decimal('final_unit_price_try', 14, 4)->nullable()->after('final_unit_price_original');
            }

            if (! Schema::hasColumn('tenant_supplier_purchase_entries', 'purchase_total_try')) {
                $table->decimal('purchase_total_try', 14, 4)->nullable()->after('final_unit_price_try');
            }

            if (! Schema::hasColumn('tenant_supplier_purchase_entries', 'manual_override')) {
                $table->boolean('manual_override')->default(false)->after('purchase_total_try');
            }

            if (! Schema::hasColumn('tenant_supplier_purchase_entries', 'idempotency_key')) {
                $table->string('idempotency_key', 120)->nullable()->after('manual_override');
            }

            if (! Schema::hasColumn('tenant_supplier_purchase_entries', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('idempotency_key');
            }

            if (! Schema::hasColumn('tenant_supplier_purchase_entries', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
            }

            if (! Schema::hasColumn('tenant_supplier_purchase_entries', 'cancellation_reason')) {
                $table->string('cancellation_reason', 500)->nullable()->after('cancelled_by');
            }

            if (! Schema::hasColumn('tenant_supplier_purchase_entries', 'meta_json')) {
                $table->json('meta_json')->nullable()->after('cancellation_reason');
            }
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_movements', 'tenant_local_stock_id')) {
                $table->unsignedBigInteger('tenant_local_stock_id')->nullable()->after('tenant_catalog_product_id');
            }

            if (! Schema::hasColumn('stock_movements', 'reference_type')) {
                $table->string('reference_type', 80)->nullable()->after('quantity');
            }

            if (! Schema::hasColumn('stock_movements', 'reference_id')) {
                $table->unsignedBigInteger('reference_id')->nullable()->after('reference_type');
            }

            if (! Schema::hasColumn('stock_movements', 'moved_by')) {
                $table->unsignedBigInteger('moved_by')->nullable()->after('notes');
            }

            if (! Schema::hasColumn('stock_movements', 'moved_at')) {
                $table->timestamp('moved_at')->nullable()->after('moved_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            foreach (['tenant_local_stock_id', 'reference_type', 'reference_id', 'moved_by', 'moved_at'] as $column) {
                if (Schema::hasColumn('stock_movements', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('tenant_supplier_purchase_entries', function (Blueprint $table) {
            foreach ([
                'tenant_catalog_product_variant_id',
                'stock_scope',
                'entry_status',
                'original_currency',
                'exchange_rate',
                'exchange_rate_date',
                'original_list_price',
                'calculated_unit_price_original',
                'final_unit_price_original',
                'final_unit_price_try',
                'purchase_total_try',
                'manual_override',
                'idempotency_key',
                'cancelled_at',
                'cancelled_by',
                'cancellation_reason',
                'meta_json',
            ] as $column) {
                if (Schema::hasColumn('tenant_supplier_purchase_entries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
