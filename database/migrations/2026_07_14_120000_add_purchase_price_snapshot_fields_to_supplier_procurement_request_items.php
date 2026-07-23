<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_procurement_request_items', function (Blueprint $table) {
            $table->decimal('purchase_source_amount', 18, 6)->nullable()->after('remaining_quantity');
            $table->char('purchase_source_currency', 3)->nullable()->after('purchase_source_amount');
            $table->decimal('purchase_fx_rate', 20, 8)->nullable()->after('purchase_source_currency');
            $table->dateTime('purchase_fx_rate_date')->nullable()->after('purchase_fx_rate');
            $table->string('purchase_fx_rate_source', 64)->nullable()->after('purchase_fx_rate_date');
            $table->decimal('purchase_list_price_try', 18, 6)->nullable()->after('purchase_list_price');
            $table->decimal('purchase_calculated_unit_price', 18, 6)->nullable()->after('purchase_list_price_try');
            $table->decimal('purchase_manual_unit_price', 18, 6)->nullable()->after('purchase_calculated_unit_price');
            $table->boolean('purchase_manual_override')->default(false)->after('purchase_manual_unit_price');
            $table->string('purchase_manual_override_reason', 500)->nullable()->after('purchase_manual_override');
            $table->char('purchase_settlement_currency', 3)->default('TRY')->after('purchase_manual_override_reason');
            $table->json('purchase_price_snapshot')->nullable()->after('purchase_settlement_currency');
            $table->unsignedSmallInteger('purchase_price_snapshot_version')->default(1)->after('purchase_price_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_procurement_request_items', function (Blueprint $table) {
            $table->dropColumn([
                'purchase_source_amount',
                'purchase_source_currency',
                'purchase_fx_rate',
                'purchase_fx_rate_date',
                'purchase_fx_rate_source',
                'purchase_list_price_try',
                'purchase_calculated_unit_price',
                'purchase_manual_unit_price',
                'purchase_manual_override',
                'purchase_manual_override_reason',
                'purchase_settlement_currency',
                'purchase_price_snapshot',
                'purchase_price_snapshot_version',
            ]);
        });
    }
};
