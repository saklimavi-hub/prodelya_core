<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_work_forms', function (Blueprint $table) {
            if (!Schema::hasColumn('order_item_work_forms', 'procurement_snapshot')) {
                $table->json('procurement_snapshot')->nullable()->after('delivery_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_item_work_forms', function (Blueprint $table) {
            if (Schema::hasColumn('order_item_work_forms', 'procurement_snapshot')) {
                $table->dropColumn('procurement_snapshot');
            }
        });
    }
};
