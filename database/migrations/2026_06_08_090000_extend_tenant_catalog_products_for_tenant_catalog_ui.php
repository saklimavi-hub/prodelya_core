<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_catalog_products', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_catalog_products', 'standard_product_id')) {
                $table->foreignId('standard_product_id')->nullable()->change();
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'visible_in_quote')) {
                $table->boolean('visible_in_quote')->default(true)->after('visible_in_catalog');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'hidden_reason')) {
                $table->string('hidden_reason')->nullable()->after('visible_in_quote');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'catalog_source')) {
                $table->string('catalog_source')->default('supplier_projection')->after('hidden_reason');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'catalog_status')) {
                $table->string('catalog_status')->nullable()->after('catalog_source');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable()->after('catalog_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_catalog_products', function (Blueprint $table) {
            foreach ([
                'visible_in_quote',
                'hidden_reason',
                'catalog_source',
                'catalog_status',
                'last_synced_at',
            ] as $column) {
                if (Schema::hasColumn('tenant_catalog_products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
