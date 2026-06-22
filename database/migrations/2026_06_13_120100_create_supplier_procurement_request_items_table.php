<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_procurement_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_procurement_request_id')->constrained('supplier_procurement_requests')->cascadeOnDelete();
            $table->foreignId('order_item_procurement_id')->constrained('order_item_procurements')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_form_id')->nullable()->constrained('order_item_work_forms')->nullOnDelete();
            $table->foreignId('supplier_source_id')->nullable()->constrained('supplier_sources')->nullOnDelete();
            $table->string('product_code')->nullable();
            $table->string('product_name');
            $table->decimal('requested_quantity', 15, 2);
            $table->string('unit')->default('Adet');
            $table->decimal('received_quantity', 15, 2)->default(0);
            $table->decimal('remaining_quantity', 15, 2);
            $table->decimal('purchase_list_price', 15, 2)->nullable();
            $table->decimal('discount_rate', 8, 2)->nullable();
            $table->decimal('purchase_unit_price', 15, 2)->nullable();
            $table->decimal('purchase_total', 15, 2)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_account_id', 'supplier_procurement_request_id'], 'spri_tenant_request_idx');
            $table->index(['tenant_account_id', 'order_item_procurement_id'], 'spri_tenant_procurement_idx');
            $table->index(['tenant_account_id', 'order_id'], 'spri_tenant_order_idx');
            $table->index(['tenant_account_id', 'work_form_id'], 'spri_tenant_work_form_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_procurement_request_items');
    }
};
