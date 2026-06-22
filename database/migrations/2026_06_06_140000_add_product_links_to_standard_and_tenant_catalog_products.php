<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standard_products', function (Blueprint $table) {
            if (!Schema::hasColumn('standard_products', 'detail_url')) {
                $table->text('detail_url')->nullable()->after('product_url');
            }
        });

        Schema::table('tenant_catalog_products', function (Blueprint $table) {
            if (!Schema::hasColumn('tenant_catalog_products', 'product_url')) {
                $table->text('product_url')->nullable()->after('image_url');
            }

            if (!Schema::hasColumn('tenant_catalog_products', 'detail_url')) {
                $table->text('detail_url')->nullable()->after('product_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('standard_products', function (Blueprint $table) {
            if (Schema::hasColumn('standard_products', 'detail_url')) {
                $table->dropColumn('detail_url');
            }
        });

        Schema::table('tenant_catalog_products', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_catalog_products', 'detail_url')) {
                $table->dropColumn('detail_url');
            }

            if (Schema::hasColumn('tenant_catalog_products', 'product_url')) {
                $table->dropColumn('product_url');
            }
        });
    }
};
