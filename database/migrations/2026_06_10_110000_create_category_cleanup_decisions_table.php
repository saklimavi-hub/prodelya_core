<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_cleanup_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->nullable()->constrained('category_tree_drafts')->nullOnDelete();
            $table->foreignId('current_category_id')->nullable()->constrained('standard_categories')->nullOnDelete();
            $table->string('current_code')->nullable();
            $table->string('current_name');
            $table->string('current_path')->nullable();
            $table->string('current_parent')->nullable();
            $table->unsignedInteger('level')->default(0);
            $table->string('product_family')->nullable();
            $table->unsignedInteger('product_count')->default(0);
            $table->unsignedInteger('supplier_mapping_count')->default(0);
            $table->unsignedInteger('child_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_visible')->default(true);
            $table->json('warning_flags')->nullable();
            $table->string('proposed_action')->default('needs_review');
            $table->string('proposed_category_code')->nullable();
            $table->string('proposed_category_name')->nullable();
            $table->string('proposed_category_path')->nullable();
            $table->string('proposed_parent')->nullable();
            $table->string('decision_status')->default('proposed');
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->text('reason')->nullable();
            $table->string('risk_level')->default('medium');
            $table->boolean('needs_user_review')->default(true);
            $table->string('feature_template_key')->nullable();
            $table->timestamps();

            $table->unique(['draft_id', 'current_category_id'], 'cleanup_decisions_draft_category_unique');
            $table->index(['proposed_action', 'decision_status']);
            $table->index(['risk_level', 'needs_user_review']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_cleanup_decisions');
    }
};
