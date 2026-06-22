<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_category_suggestion_logs')) {
            return;
        }

        Schema::create('product_category_suggestion_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_source_id')->nullable()->constrained('supplier_sources')->nullOnDelete();
            $table->string('supplier_product_id')->nullable();
            $table->string('supplier_product_code')->nullable();
            $table->string('supplier_product_name');
            $table->string('supplier_category_name')->nullable();
            $table->text('product_image_url')->nullable();
            $table->foreignId('suggested_category_id')->nullable()->constrained('standard_categories')->nullOnDelete();
            $table->foreignId('accepted_category_id')->nullable()->constrained('standard_categories')->nullOnDelete();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->decimal('name_score', 5, 2)->nullable();
            $table->decimal('category_score', 5, 2)->nullable();
            $table->decimal('attribute_score', 5, 2)->nullable();
            $table->decimal('code_score', 5, 2)->nullable();
            $table->decimal('image_score', 5, 2)->nullable();
            $table->decimal('history_score', 5, 2)->nullable();
            $table->string('decision_status')->default('pending');
            $table->text('decision_reason')->nullable();
            $table->json('raw_signals')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['supplier_source_id', 'supplier_category_name'], 'prod_cat_suggestion_source_category_idx');
            $table->index(['decision_status', 'confidence_score'], 'prod_cat_suggestion_status_conf_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_category_suggestion_logs');
    }
};
