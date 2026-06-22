<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenant_supplier_access', function (Blueprint $table) {
            if (!Schema::hasColumn('tenant_supplier_access', 'can_view_products')) {
                $table->boolean('can_view_products')->default(true)->after('is_active');
            }

            if (!Schema::hasColumn('tenant_supplier_access', 'can_request_purchase')) {
                $table->boolean('can_request_purchase')->default(true)->after('can_view_products');
            }

            if (!Schema::hasColumn('tenant_supplier_access', 'can_use_in_quotes')) {
                $table->boolean('can_use_in_quotes')->default(true)->after('can_request_purchase');
            }

            if (!Schema::hasColumn('tenant_supplier_access', 'price_multiplier')) {
                $table->decimal('price_multiplier', 8, 2)->nullable()->after('can_use_in_quotes');
            }

            if (!Schema::hasColumn('tenant_supplier_access', 'safe_stock_quantity')) {
                $table->integer('safe_stock_quantity')->nullable()->after('price_multiplier');
            }

            if (!Schema::hasColumn('tenant_supplier_access', 'visible_in_catalog')) {
                $table->boolean('visible_in_catalog')->default(true)->after('safe_stock_quantity');
            }

            if (!Schema::hasColumn('tenant_supplier_access', 'export_allowed')) {
                $table->boolean('export_allowed')->default(false)->after('visible_in_catalog');
            }

            if (!Schema::hasColumn('tenant_supplier_access', 'meta')) {
                $table->json('meta')->nullable()->after('export_allowed');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_supplier_access', function (Blueprint $table) {
            $columns = [
                'can_view_products',
                'can_request_purchase',
                'can_use_in_quotes',
                'price_multiplier',
                'safe_stock_quantity',
                'visible_in_catalog',
                'export_allowed',
                'meta',
            ];

            $existing = array_filter($columns, fn (string $column) => Schema::hasColumn('tenant_supplier_access', $column));

            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
