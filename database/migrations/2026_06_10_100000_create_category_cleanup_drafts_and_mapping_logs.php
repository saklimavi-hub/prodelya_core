<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('category_tree_drafts')) {
            Schema::create('category_tree_drafts', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('status')->default('draft')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('category_tree_draft_items')) {
            Schema::create('category_tree_draft_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('draft_id')->constrained('category_tree_drafts')->cascadeOnDelete();
                $table->unsignedBigInteger('parent_id')->nullable()->index();
                $table->string('proposed_code')->nullable()->index();
                $table->string('proposed_name');
                $table->string('product_family')->nullable()->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_visible')->default(true);
                $table->boolean('is_active')->default(true);
                $table->foreignId('source_category_id')->nullable()->constrained('standard_categories')->nullOnDelete();
                $table->foreignId('canonical_target_id')->nullable()->constrained('standard_categories')->nullOnDelete();
                $table->string('action_type')->default('keep')->index();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('supplier_category_mapping_logs')) {
            Schema::create('supplier_category_mapping_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mapping_id')->constrained('supplier_category_mappings')->cascadeOnDelete();
                $table->foreignId('old_standard_category_id')->nullable()->constrained('standard_categories')->nullOnDelete();
                $table->foreignId('new_standard_category_id')->nullable()->constrained('standard_categories')->nullOnDelete();
                $table->string('action')->index();
                $table->text('reason')->nullable();
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['mapping_id', 'action']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_category_mapping_logs');
        Schema::dropIfExists('category_tree_draft_items');
        Schema::dropIfExists('category_tree_drafts');
    }
};
