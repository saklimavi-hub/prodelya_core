<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_local_stocks', function (Blueprint $table) {
            $table->foreignId('tenant_catalog_product_variant_id')
                ->nullable()
                ->after('tenant_catalog_product_id')
                ->constrained('tenant_catalog_product_variants')
                ->nullOnDelete();
            $table->string('stock_scope', 24)->default('product')->after('tenant_catalog_product_variant_id');
            $table->string('legacy_assignment_status', 32)->nullable()->after('stock_scope');
            $table->dropUnique('tenant_local_stocks_tenant_catalog_product_id_warehouse_code_location_code_unique');
            $table->unique(
                [
                    'tenant_account_id',
                    'tenant_catalog_product_id',
                    'tenant_catalog_product_variant_id',
                    'warehouse_code',
                    'location_code',
                ],
                'tenant_local_stocks_scope_unique'
            );
            $table->index(
                ['tenant_account_id', 'tenant_catalog_product_id', 'tenant_catalog_product_variant_id'],
                'tenant_local_stocks_exact_scope_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('tenant_local_stocks', function (Blueprint $table) {
            $table->dropIndex('tenant_local_stocks_exact_scope_index');
            $table->dropUnique('tenant_local_stocks_scope_unique');
            $table->unique(
                ['tenant_catalog_product_id', 'warehouse_code', 'location_code'],
                'tenant_local_stocks_tenant_catalog_product_id_warehouse_code_location_code_unique'
            );
            $table->dropConstrainedForeignId('tenant_catalog_product_variant_id');
            $table->dropColumn(['stock_scope', 'legacy_assignment_status']);
        });
    }
};
