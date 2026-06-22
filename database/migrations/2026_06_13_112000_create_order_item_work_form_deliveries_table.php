<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_work_form_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_account_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('work_form_id');
            $table->string('delivery_status', 50)->default('teslimat_bekliyor');
            $table->string('delivery_method', 50)->nullable();
            $table->decimal('planned_quantity', 12, 4);
            $table->decimal('delivered_quantity', 12, 4)->default(0);
            $table->decimal('remaining_quantity', 12, 4)->default(0);
            $table->string('carrier_name', 160)->nullable();
            $table->string('tracking_number', 160)->nullable();
            $table->string('recipient_name', 160)->nullable();
            $table->string('recipient_phone', 60)->nullable();
            $table->text('delivery_note')->nullable();
            $table->string('financial_warning', 50)->nullable();
            $table->json('delivery_snapshot')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('partially_delivered_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('issue_reported_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_account_id', 'work_form_id'], 'oiwfd_tenant_work_form_unique');
            $table->index(['tenant_account_id', 'order_id'], 'oiwfd_tenant_order_index');
            $table->index(['tenant_account_id', 'order_item_id'], 'oiwfd_tenant_order_item_index');
            $table->index(['tenant_account_id', 'delivery_status'], 'oiwfd_tenant_status_index');
            $table->index(['tenant_account_id', 'delivery_method'], 'oiwfd_tenant_method_index');
            $table->index('work_form_id', 'oiwfd_work_form_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_work_form_deliveries');
    }
};
