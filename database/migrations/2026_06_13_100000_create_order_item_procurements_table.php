<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_procurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_form_id')->nullable()->constrained('order_item_work_forms')->nullOnDelete();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->foreignId('supplier_source_id')->nullable()->constrained('supplier_sources')->nullOnDelete();
            $table->boolean('requires_procurement')->default(true);
            $table->string('fulfillment_source', 40);
            $table->string('procurement_status', 60);
            $table->decimal('requested_quantity', 15, 4);
            $table->decimal('local_allocated_quantity', 15, 4)->default(0);
            $table->decimal('supplier_requested_quantity', 15, 4)->default(0);
            $table->decimal('received_quantity', 15, 4)->default(0);
            $table->decimal('remaining_quantity', 15, 4)->default(0);
            $table->json('snapshot')->nullable();
            $table->json('procurement_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('partially_received_at')->nullable();
            $table->timestamp('fully_received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_account_id', 'order_item_id'], 'oip_tenant_order_item_unique');
            $table->index(['tenant_account_id', 'order_id'], 'oip_tenant_order_idx');
            $table->index(['tenant_account_id', 'procurement_status'], 'oip_tenant_status_idx');
            $table->index(['tenant_account_id', 'fulfillment_source'], 'oip_tenant_source_idx');
            $table->index('supplier_id');
            $table->index('supplier_source_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_procurements');
    }
};
