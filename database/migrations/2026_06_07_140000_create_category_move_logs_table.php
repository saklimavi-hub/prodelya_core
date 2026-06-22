<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('category_move_logs')) {
            return;
        }

        Schema::create('category_move_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('standard_categories')->cascadeOnDelete();
            $table->foreignId('old_parent_id')->nullable()->constrained('standard_categories')->nullOnDelete();
            $table->foreignId('new_parent_id')->nullable()->constrained('standard_categories')->nullOnDelete();
            $table->string('old_path')->nullable();
            $table->string('new_path')->nullable();
            $table->integer('old_sort_order')->nullable();
            $table->integer('new_sort_order')->nullable();
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['category_id', 'moved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_move_logs');
    }
};
