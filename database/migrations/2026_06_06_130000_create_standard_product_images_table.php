<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standard_product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('standard_product_id')->nullable()->constrained('standard_products')->nullOnDelete();
            $table->foreignId('standard_product_variant_id')->nullable()->constrained('standard_product_variants')->nullOnDelete();
            $table->text('image_url');
            $table->string('image_type')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->boolean('fallback_used')->default(false);
            $table->foreignId('source_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('source_supplier_source_id')->nullable()->constrained('supplier_sources')->nullOnDelete();
            $table->foreignId('source_raw_product_id')->nullable()->constrained('supplier_products_raw')->nullOnDelete();
            $table->foreignId('source_raw_variant_id')->nullable()->constrained('supplier_product_variants_raw')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('standard_product_id');
            $table->index('standard_product_variant_id');
            $table->index('image_type');
            $table->index('is_primary');
            $table->unique(['standard_product_id', 'standard_product_variant_id', 'image_url', 'image_type'], 'std_product_images_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standard_product_images');
    }
};
