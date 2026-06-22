<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('standard_product_variants')) {
            return;
        }

        Schema::create('standard_product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('standard_product_id')->constrained('standard_products')->cascadeOnDelete();
            $table->foreignId('tenant_account_id')->nullable()->constrained('tenant_accounts')->nullOnDelete();
            $table->string('variant_code')->nullable();
            $table->string('generated_variant_code')->nullable();
            $table->string('variant_name')->nullable();
            $table->string('variant_color')->nullable();
            $table->string('variant_size')->nullable();
            $table->json('variant_attributes')->nullable();
            $table->text('image_url')->nullable();
            $table->boolean('image_fallback_used')->default(false);
            $table->decimal('stock_quantity', 15, 4)->nullable();
            $table->decimal('min_purchase_price', 15, 4)->nullable();
            $table->decimal('max_purchase_price', 15, 4)->nullable();
            $table->integer('supplier_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('visible_in_catalog')->default(true);
            $table->json('source_summary')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('standard_product_id');
            $table->index('generated_variant_code');
            $table->index('variant_code');
            $table->index('variant_color');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standard_product_variants');
    }
};
