<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_product_variants_raw')) {
            return;
        }

        Schema::create('supplier_product_variants_raw', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->nullable()->constrained('tenant_accounts')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_product_raw_id')->nullable()->constrained('supplier_products_raw')->nullOnDelete();
            $table->string('parent_supplier_product_id')->nullable();
            $table->string('supplier_group_code')->nullable();
            $table->string('variant_id')->nullable();
            $table->string('variant_code')->nullable();
            $table->string('variant_stock_code')->nullable();
            $table->string('variant_name')->nullable();
            $table->string('variant_color')->nullable();
            $table->string('variant_size')->nullable();
            $table->json('variant_attributes')->nullable();
            $table->decimal('variant_stock_quantity', 15, 4)->nullable();
            $table->text('variant_image_url')->nullable();
            $table->text('parent_image_url')->nullable();
            $table->boolean('image_fallback_used')->default(false);
            $table->string('generated_variant_code')->nullable();
            $table->json('raw_payload')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->json('warnings')->nullable();
            $table->json('errors')->nullable();
            $table->string('import_hash', 64)->nullable()->index();
            $table->string('sync_status', 40)->nullable()->default('staged');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product_variants_raw');
    }
};
