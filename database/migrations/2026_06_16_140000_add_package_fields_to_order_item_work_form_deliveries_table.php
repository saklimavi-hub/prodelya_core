<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_work_form_deliveries', function (Blueprint $table): void {
            if (!Schema::hasColumn('order_item_work_form_deliveries', 'package_count')) {
                $table->integer('package_count')->nullable()->after('remaining_quantity');
            }

            if (!Schema::hasColumn('order_item_work_form_deliveries', 'units_per_package')) {
                $table->integer('units_per_package')->nullable()->after('package_count');
            }

            if (!Schema::hasColumn('order_item_work_form_deliveries', 'packaged_quantity')) {
                $table->integer('packaged_quantity')->nullable()->after('units_per_package');
            }

            if (!Schema::hasColumn('order_item_work_form_deliveries', 'package_type')) {
                $table->string('package_type', 40)->nullable()->after('packaged_quantity');
            }

            if (!Schema::hasColumn('order_item_work_form_deliveries', 'package_note')) {
                $table->text('package_note')->nullable()->after('package_type');
            }

            if (!Schema::hasColumn('order_item_work_form_deliveries', 'delivery_document_no')) {
                $table->string('delivery_document_no', 160)->nullable()->after('recipient_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_item_work_form_deliveries', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('order_item_work_form_deliveries', 'package_count') ? 'package_count' : null,
                Schema::hasColumn('order_item_work_form_deliveries', 'units_per_package') ? 'units_per_package' : null,
                Schema::hasColumn('order_item_work_form_deliveries', 'packaged_quantity') ? 'packaged_quantity' : null,
                Schema::hasColumn('order_item_work_form_deliveries', 'package_type') ? 'package_type' : null,
                Schema::hasColumn('order_item_work_form_deliveries', 'package_note') ? 'package_note' : null,
                Schema::hasColumn('order_item_work_form_deliveries', 'delivery_document_no') ? 'delivery_document_no' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
