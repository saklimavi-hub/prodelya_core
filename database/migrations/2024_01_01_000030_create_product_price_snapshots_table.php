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
        Schema::create('product_price_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('tenant_catalog_product_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('sale_price', 15, 2);
            $table->string('currency', 3);
            $table->decimal('exchange_rate', 10, 6)->nullable(); // For foreign currencies
            $table->decimal('sale_price_tl', 15, 2)->nullable(); // Converted to TL
            $table->decimal('cost_price', 15, 2)->nullable();
            $table->decimal('profit_margin', 8, 4)->nullable(); // Percentage
            $table->enum('snapshot_type', ['quote', 'order', 'catalog_update']);
            $table->timestamp('effective_date');
            $table->timestamps();
            
            $table->index(['tenant_catalog_product_id', 'effective_date']);
            $table->index(['order_id', 'snapshot_type']);
            $table->index(['tenant_account_id', 'currency']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_price_snapshots');
    }
};
