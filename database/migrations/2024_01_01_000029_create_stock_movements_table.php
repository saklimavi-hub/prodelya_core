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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('tenant_catalog_product_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('movement_type', ['in', 'out', 'adjustment', 'transfer', 'return']);
            $table->enum('reason', ['purchase', 'sale', 'adjustment', 'damage', 'transfer_in', 'transfer_out', 'return_from_customer']);
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('warehouse_code')->nullable();
            $table->string('location_code')->nullable();
            $table->string('reference_document')->nullable(); // Invoice, order number, etc.
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            
            $table->index(['tenant_account_id', 'movement_type']);
            $table->index(['tenant_catalog_product_id', 'created_at']);
            $table->index(['order_id', 'movement_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
