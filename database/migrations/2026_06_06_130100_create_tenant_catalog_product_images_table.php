<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_catalog_product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained('tenant_accounts')->cascadeOnDelete();
            $table->foreignId('tenant_catalog_product_id')->nullable()->constrained('tenant_catalog_products')->cascadeOnDelete();
            $table->foreignId('tenant_catalog_product_variant_id')->nullable()->constrained('tenant_catalog_product_variants')->nullOnDelete();
            $table->foreignId('standard_product_image_id')->nullable()->constrained('standard_product_images')->nullOnDelete();
            $table->text('image_url');
            $table->string('image_type')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->boolean('fallback_used')->default(false);
            $table->boolean('visible_in_catalog')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('tenant_account_id');
            $table->index('tenant_catalog_product_id');
            $table->index('tenant_catalog_product_variant_id');
            $table->index('image_type');
            $table->index('visible_in_catalog');
            $table->unique(['tenant_account_id', 'tenant_catalog_product_id', 'tenant_catalog_product_variant_id', 'image_url', 'image_type'], 'tenant_catalog_images_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_catalog_product_images');
    }
};
