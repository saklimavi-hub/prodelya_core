<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'source_quote_number')) {
                $table->string('source_quote_number')->nullable()->after('source_quote_id');
            }

            if (!Schema::hasColumn('orders', 'product_total')) {
                $table->decimal('product_total', 15, 2)->nullable()->after('grand_total');
            }

            if (!Schema::hasColumn('orders', 'print_total')) {
                $table->decimal('print_total', 15, 2)->nullable()->after('product_total');
            }

            if (!Schema::hasColumn('orders', 'vat_breakdown_json')) {
                $table->json('vat_breakdown_json')->nullable()->after('print_total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['vat_breakdown_json', 'print_total', 'product_total', 'source_quote_number'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
