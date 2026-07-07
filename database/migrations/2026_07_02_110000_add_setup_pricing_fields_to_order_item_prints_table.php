<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_prints', function (Blueprint $table): void {
            if (!Schema::hasColumn('order_item_prints', 'setup_pricing_enabled')) {
                $table->boolean('setup_pricing_enabled')
                    ->default(false)
                    ->after('cliche_status');
            }

            if (!Schema::hasColumn('order_item_prints', 'setup_type')) {
                $table->string('setup_type')->nullable()->after('setup_pricing_enabled');
            }

            if (!Schema::hasColumn('order_item_prints', 'setup_status')) {
                $table->string('setup_status')->nullable()->after('setup_type');
            }

            if (!Schema::hasColumn('order_item_prints', 'setup_total_amount')) {
                $table->decimal('setup_total_amount', 15, 4)->nullable()->after('setup_status');
            }

            if (!Schema::hasColumn('order_item_prints', 'setup_distribution_quantity')) {
                $table->decimal('setup_distribution_quantity', 15, 4)->nullable()->after('setup_total_amount');
            }

            if (!Schema::hasColumn('order_item_prints', 'setup_unit_amount')) {
                $table->decimal('setup_unit_amount', 15, 4)->nullable()->after('setup_distribution_quantity');
            }

            if (!Schema::hasColumn('order_item_prints', 'base_print_unit_price')) {
                $table->decimal('base_print_unit_price', 15, 4)->nullable()->after('setup_unit_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_item_prints', function (Blueprint $table): void {
            foreach ([
                'base_print_unit_price',
                'setup_unit_amount',
                'setup_distribution_quantity',
                'setup_total_amount',
                'setup_status',
                'setup_type',
                'setup_pricing_enabled',
            ] as $column) {
                if (Schema::hasColumn('order_item_prints', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
