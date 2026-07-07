<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_delivery_package_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained('tenant_accounts');
            $table->foreignId('order_delivery_package_id')->constrained('order_delivery_packages')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders');
            $table->foreignId('order_item_id')->constrained('order_items');
            $table->decimal('quantity', 12, 4);
            $table->string('item_name_snapshot');
            $table->string('item_sku_snapshot')->nullable();
            $table->timestamps();

            $table->index(['tenant_account_id', 'order_id']);
            $table->index(['order_delivery_package_id', 'order_item_id'], 'order_delivery_package_items_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_delivery_package_items');
    }
};
