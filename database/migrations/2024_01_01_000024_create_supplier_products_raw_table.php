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
        Schema::create('supplier_products_raw', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            $table->foreignId('supplier_source_id')->constrained()->onDelete('cascade');
            $table->string('source_product_id'); // Product ID in supplier system
            $table->string('source_sku')->nullable();
            $table->string('source_name');
            $table->text('source_description')->nullable();
            $table->string('source_category')->nullable();
            $table->decimal('source_price', 15, 2)->nullable();
            $table->string('source_currency', 3)->nullable();
            $table->integer('source_stock')->nullable();
            $table->json('source_attributes')->nullable(); // All raw data as JSON
            $table->enum('sync_status', ['pending', 'processed', 'error', 'skipped'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            
            $table->unique(['supplier_source_id', 'source_product_id']);
            $table->index(['supplier_id', 'sync_status']);
            $table->index('source_sku');
            $table->index('source_category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_products_raw');
    }
};
