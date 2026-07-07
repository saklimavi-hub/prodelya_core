<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_prints', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_item_prints', 'tenant_print_option_id')) {
                $table->foreignId('tenant_print_option_id')
                    ->nullable()
                    ->after('standard_print_type_id')
                    ->constrained('tenant_print_options')
                    ->nullOnDelete();
                $table->index('tenant_print_option_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_item_prints', function (Blueprint $table): void {
            if (Schema::hasColumn('order_item_prints', 'tenant_print_option_id')) {
                $table->dropIndex(['tenant_print_option_id']);
                $table->dropConstrainedForeignId('tenant_print_option_id');
            }
        });
    }
};
