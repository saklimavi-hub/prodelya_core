<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenant_catalog_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('standard_product_id')->constrained()->onDelete('cascade');
            $table->string('tenant_sku')->unique(); // Tenant-specific SKU
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('sale_price', 15, 2)->nullable();
            $table->string('currency', 3)->default('TL');
            $table->integer('stock_quantity')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('allow_backorder')->default(false);
            $table->decimal('min_order_quantity', 15, 4)->default(1);
            $table->json('tenant_attributes')->nullable(); // Tenant-specific attributes
            $table->timestamps();
            
            $table->unique(['tenant_account_id', 'tenant_sku']);
            $table->index(['tenant_account_id', 'is_active']);
            $table->index(['tenant_account_id', 'stock_quantity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_catalog_products');
    }
};
