<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'source_order_id')) {
                $table->foreignId('source_order_id')->nullable()->after('source_quote_number')->constrained('orders')->nullOnDelete();
            }

            if (! Schema::hasColumn('orders', 'copy_type')) {
                $table->string('copy_type', 40)->nullable()->after('source_order_id');
            }

            if (! Schema::hasColumn('orders', 'revision_number')) {
                $table->unsignedInteger('revision_number')->nullable()->after('copy_type');
            }

            if (! Schema::hasColumn('orders', 'copied_by_user_id')) {
                $table->foreignId('copied_by_user_id')->nullable()->after('revision_number')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('orders', 'copied_at')) {
                $table->timestamp('copied_at')->nullable()->after('copied_by_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'copied_at')) {
                $table->dropColumn('copied_at');
            }

            if (Schema::hasColumn('orders', 'copied_by_user_id')) {
                $table->dropConstrainedForeignId('copied_by_user_id');
            }

            if (Schema::hasColumn('orders', 'revision_number')) {
                $table->dropColumn('revision_number');
            }

            if (Schema::hasColumn('orders', 'copy_type')) {
                $table->dropColumn('copy_type');
            }

            if (Schema::hasColumn('orders', 'source_order_id')) {
                $table->dropConstrainedForeignId('source_order_id');
            }
        });
    }
};
