<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('category_merge_logs')) {
            return;
        }

        Schema::create('category_merge_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_category_id')->constrained('standard_categories')->cascadeOnDelete();
            $table->foreignId('target_category_id')->constrained('standard_categories')->cascadeOnDelete();
            $table->string('old_code')->nullable();
            $table->string('new_code')->nullable();
            $table->unsignedInteger('moved_products_count')->default(0);
            $table->unsignedInteger('moved_mappings_count')->default(0);
            $table->unsignedInteger('moved_aliases_count')->default(0);
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('merged_at')->nullable();
            $table->text('notes')->nullable();

            $table->index(['source_category_id', 'target_category_id'], 'cat_merge_source_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_merge_logs');
    }
};
