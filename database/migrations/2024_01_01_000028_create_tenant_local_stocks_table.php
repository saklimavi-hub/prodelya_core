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
        Schema::create('tenant_local_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('tenant_catalog_product_id')->constrained()->onDelete('cascade');
            $table->string('warehouse_code')->nullable();
            $table->string('location_code')->nullable(); // Shelf, bin location
            $table->decimal('quantity_on_hand', 15, 4)->default(0);
            $table->decimal('quantity_reserved', 15, 4)->default(0);
            $table->decimal('quantity_available', 15, 4)->default(0);
            $table->decimal('reorder_level', 15, 4)->nullable();
            $table->decimal('max_stock', 15, 4)->nullable();
            $table->timestamp('last_counted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->unique(['tenant_catalog_product_id', 'warehouse_code', 'location_code']);
            $table->index(['tenant_account_id', 'warehouse_code']);
            $table->index(['tenant_account_id', 'quantity_available']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_local_stocks');
    }
};
