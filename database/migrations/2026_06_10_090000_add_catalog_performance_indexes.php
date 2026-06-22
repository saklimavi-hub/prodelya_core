<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_catalog_products')) {
            Schema::table('tenant_catalog_products', function (Blueprint $table) {
                $table->index(['tenant_account_id', 'is_active', 'visible_in_catalog'], 'tcp_tenant_active_catalog_idx');
                $table->index(['tenant_account_id', 'visible_in_quote'], 'tcp_tenant_quote_idx');
                $table->index(['tenant_account_id', 'catalog_source'], 'tcp_tenant_source_idx');
                $table->index(['tenant_account_id', 'catalog_status'], 'tcp_tenant_status_idx');
                $table->index(['tenant_account_id', 'local_stock_quantity'], 'tcp_tenant_local_stock_idx');
                $table->index(['tenant_account_id', 'standard_category_id'], 'tcp_tenant_category_idx');
                $table->index(['tenant_account_id', 'product_code'], 'tcp_tenant_product_code_idx');
            });
        }

        if (Schema::hasTable('tenant_catalog_product_variants')) {
            Schema::table('tenant_catalog_product_variants', function (Blueprint $table) {
                $table->index(['tenant_account_id', 'tenant_catalog_product_id', 'is_active', 'visible_in_catalog'], 'tcpv_tenant_product_visible_idx');
                $table->index(['tenant_account_id', 'local_stock_quantity'], 'tcpv_tenant_local_stock_idx');
            });
        }

        if (Schema::hasTable('tenant_supplier_access')) {
            Schema::table('tenant_supplier_access', function (Blueprint $table) {
                $table->index(['tenant_account_id', 'is_active', 'can_view_products', 'visible_in_catalog'], 'tsa_tenant_catalog_access_idx');
            });
        }

        if (Schema::hasTable('standard_products')) {
            Schema::table('standard_products', function (Blueprint $table) {
                $table->index(['supplier_source_id', 'standard_category_id'], 'sp_source_category_idx');
                $table->index(['supplier_source_id', 'standard_product_code'], 'sp_source_code_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('standard_products')) {
            Schema::table('standard_products', function (Blueprint $table) {
                $table->dropIndex('sp_source_code_idx');
                $table->dropIndex('sp_source_category_idx');
            });
        }

        if (Schema::hasTable('tenant_supplier_access')) {
            Schema::table('tenant_supplier_access', function (Blueprint $table) {
                $table->dropIndex('tsa_tenant_catalog_access_idx');
            });
        }

        if (Schema::hasTable('tenant_catalog_product_variants')) {
            Schema::table('tenant_catalog_product_variants', function (Blueprint $table) {
                $table->dropIndex('tcpv_tenant_local_stock_idx');
                $table->dropIndex('tcpv_tenant_product_visible_idx');
            });
        }

        if (Schema::hasTable('tenant_catalog_products')) {
            Schema::table('tenant_catalog_products', function (Blueprint $table) {
                $table->dropIndex('tcp_tenant_product_code_idx');
                $table->dropIndex('tcp_tenant_category_idx');
                $table->dropIndex('tcp_tenant_local_stock_idx');
                $table->dropIndex('tcp_tenant_status_idx');
                $table->dropIndex('tcp_tenant_source_idx');
                $table->dropIndex('tcp_tenant_quote_idx');
                $table->dropIndex('tcp_tenant_active_catalog_idx');
            });
        }
    }
};
