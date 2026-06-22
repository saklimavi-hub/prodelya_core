<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_supplier_purchase_entries', function (Blueprint $table) {
            $table->decimal('list_price', 15, 4)->nullable()->after('quantity');
            $table->decimal('discount_rate', 8, 4)->default(0)->after('list_price');
            $table->decimal('calculated_purchase_unit_price', 15, 4)->nullable()->after('discount_rate');
            $table->boolean('manual_purchase_unit_price')->default(false)->after('unit_purchase_price');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_supplier_purchase_entries', function (Blueprint $table) {
            $table->dropColumn([
                'list_price',
                'discount_rate',
                'calculated_purchase_unit_price',
                'manual_purchase_unit_price',
            ]);
        });
    }
};
