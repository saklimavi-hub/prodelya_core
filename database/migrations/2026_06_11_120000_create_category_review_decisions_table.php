<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_review_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('batch_code', 32);
            $table->foreignId('supplier_category_mapping_id')->nullable()->constrained('supplier_category_mappings')->nullOnDelete();
            $table->string('supplier')->nullable();
            $table->string('supplier_category_code')->nullable();
            $table->string('supplier_category_name')->nullable();
            $table->text('supplier_category_path')->nullable();
            $table->foreignId('suggested_target_category_id')->nullable()->constrained('standard_categories')->nullOnDelete();
            $table->foreignId('final_target_category_id')->nullable()->constrained('standard_categories')->nullOnDelete();
            $table->string('suggested_decision')->nullable();
            $table->string('final_decision')->nullable();
            $table->string('suggested_feature')->nullable();
            $table->string('final_feature')->nullable();
            $table->string('user_decision_status')->default('held');
            $table->text('user_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['batch_code', 'supplier_category_mapping_id'], 'review_decisions_batch_mapping_unique');
            $table->index(['batch_code', 'user_decision_status']);
            $table->index(['final_decision', 'final_target_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_review_decisions');
    }
};
