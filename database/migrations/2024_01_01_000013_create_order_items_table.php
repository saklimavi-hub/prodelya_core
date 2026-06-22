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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->enum('item_type', ['product', 'customer_supplied_product', 'print_service', 'service'])->nullable();
            $table->enum('product_source', ['local_stock', 'supplier_feed', 'customer_supplied', 'manual'])->nullable();
            $table->string('product_name');
            $table->string('product_code')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('companies')->onDelete('set null');
            $table->decimal('quantity', 15, 4);
            $table->string('unit')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->nullable();
            $table->timestamps();
            
            $table->index(['order_id', 'status']);
            $table->index(['supplier_id', 'status']);
            $table->index(['product_source', 'item_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
