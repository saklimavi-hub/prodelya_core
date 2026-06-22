<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'quote_date')) {
                $table->date('quote_date')->nullable()->after('workflow_status');
            }

            if (!Schema::hasColumn('orders', 'valid_until')) {
                $table->date('valid_until')->nullable()->after('quote_date');
            }

            if (!Schema::hasColumn('orders', 'invoice_status')) {
                $table->string('invoice_status', 30)->nullable()->after('valid_until');
            }

            if (!Schema::hasColumn('orders', 'delivery_type')) {
                $table->string('delivery_type', 100)->nullable()->after('invoice_status');
            }

            if (!Schema::hasColumn('orders', 'notes')) {
                $table->text('notes')->nullable()->after('delivery_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['notes', 'delivery_type', 'invoice_status', 'valid_until', 'quote_date'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
