<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_catalog_product_variants')) {
            return;
        }

        Schema::create('tenant_catalog_product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained('tenant_accounts')->cascadeOnDelete();
            $table->foreignId('tenant_catalog_product_id')->constrained('tenant_catalog_products')->cascadeOnDelete();
            $table->foreignId('standard_product_variant_id')->nullable()->constrained('standard_product_variants')->nullOnDelete();
            $table->string('variant_code')->nullable();
            $table->string('variant_name')->nullable();
            $table->string('variant_color')->nullable();
            $table->string('variant_size')->nullable();
            $table->text('image_url')->nullable();
            $table->decimal('display_price', 15, 4)->nullable();
            $table->string('currency', 10)->nullable()->default('TL');
            $table->decimal('stock_quantity', 15, 4)->nullable();
            $table->decimal('local_stock_quantity', 15, 4)->nullable();
            $table->decimal('supplier_stock_quantity', 15, 4)->nullable();
            $table->integer('safe_stock_quantity')->nullable();
            $table->boolean('visible_in_catalog')->default(true);
            $table->boolean('is_active')->default(true);
            $table->json('source_summary')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('tenant_account_id');
            $table->index('tenant_catalog_product_id');
            $table->index('standard_product_variant_id');
            $table->index('variant_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_catalog_product_variants');
    }
};
