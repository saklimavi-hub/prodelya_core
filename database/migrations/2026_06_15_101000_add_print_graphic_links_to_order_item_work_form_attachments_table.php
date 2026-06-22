<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_work_form_attachments', function (Blueprint $table): void {
            if (!Schema::hasColumn('order_item_work_form_attachments', 'order_item_print_graphic_id')) {
                $table->foreignId('order_item_print_graphic_id')
                    ->nullable()
                    ->after('order_item_id')
                    ->constrained('order_item_print_graphics')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('order_item_work_form_attachments', 'order_item_print_id')) {
                $table->foreignId('order_item_print_id')
                    ->nullable()
                    ->after('order_item_print_graphic_id')
                    ->constrained('order_item_prints')
                    ->nullOnDelete();
            }
        });

        Schema::table('order_item_work_form_attachments', function (Blueprint $table): void {
            $table->index('order_item_print_graphic_id', 'oiwfa_print_graphic_index');
            $table->index('order_item_print_id', 'oiwfa_print_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_item_work_form_attachments', function (Blueprint $table): void {
            if (Schema::hasColumn('order_item_work_form_attachments', 'order_item_print_graphic_id')) {
                $table->dropIndex('oiwfa_print_graphic_index');
            }

            if (Schema::hasColumn('order_item_work_form_attachments', 'order_item_print_id')) {
                $table->dropIndex('oiwfa_print_index');
            }
        });

        Schema::table('order_item_work_form_attachments', function (Blueprint $table): void {
            if (Schema::hasColumn('order_item_work_form_attachments', 'order_item_print_id')) {
                $table->dropConstrainedForeignId('order_item_print_id');
            }

            if (Schema::hasColumn('order_item_work_form_attachments', 'order_item_print_graphic_id')) {
                $table->dropConstrainedForeignId('order_item_print_graphic_id');
            }
        });
    }
};
