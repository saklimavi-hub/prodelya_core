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
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('list_price', 15, 4)->nullable()->after('description');
            $table->decimal('discount_rate', 8, 4)->nullable()->default(0)->after('list_price');
            $table->decimal('unit_price', 15, 4)->nullable()->after('discount_rate');
            $table->decimal('line_total', 15, 4)->nullable()->after('unit_price');
            $table->boolean('has_print')->default(false)->after('line_total');
            $table->decimal('print_total', 15, 4)->nullable()->default(0)->after('has_print');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['list_price', 'discount_rate', 'unit_price', 'line_total', 'has_print', 'print_total']);
        });
    }
};
