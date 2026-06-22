<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_catalog_products', function (Blueprint $table) {
            if (!Schema::hasColumn('tenant_catalog_products', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('visible_in_quote');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'local_stock_priority')) {
                $table->boolean('local_stock_priority')->default(true)->after('is_featured');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_catalog_products', function (Blueprint $table) {
            foreach (['local_stock_priority', 'is_featured'] as $column) {
                if (Schema::hasColumn('tenant_catalog_products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
