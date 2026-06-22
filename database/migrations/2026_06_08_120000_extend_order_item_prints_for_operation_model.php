<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_prints', function (Blueprint $table) {
            if (!Schema::hasColumn('order_item_prints', 'production_type')) {
                $table->string('production_type')->nullable()->after('print_location');
            }

            if (!Schema::hasColumn('order_item_prints', 'subcontractor_company_id')) {
                $table->unsignedBigInteger('subcontractor_company_id')->nullable()->after('production_type');
            }

            if (!Schema::hasColumn('order_item_prints', 'print_size')) {
                $table->string('print_size')->nullable()->after('print_color');
            }

            if (!Schema::hasColumn('order_item_prints', 'production_note')) {
                $table->text('production_note')->nullable()->after('note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_item_prints', function (Blueprint $table) {
            foreach (['production_note', 'print_size', 'subcontractor_company_id', 'production_type'] as $column) {
                if (Schema::hasColumn('order_item_prints', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
