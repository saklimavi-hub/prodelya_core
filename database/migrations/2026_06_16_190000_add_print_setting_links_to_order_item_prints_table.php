<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_prints', function (Blueprint $table): void {
            $table->foreignId('tenant_print_setting_id')
                ->nullable()
                ->after('order_item_id')
                ->constrained('tenant_print_settings')
                ->nullOnDelete();
            $table->foreignId('standard_print_type_id')
                ->nullable()
                ->after('tenant_print_setting_id')
                ->constrained('standard_print_types')
                ->nullOnDelete();

            $table->index('tenant_print_setting_id');
            $table->index('standard_print_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_item_prints', function (Blueprint $table): void {
            $table->dropIndex(['tenant_print_setting_id']);
            $table->dropIndex(['standard_print_type_id']);
            $table->dropConstrainedForeignId('tenant_print_setting_id');
            $table->dropConstrainedForeignId('standard_print_type_id');
        });
    }
};
