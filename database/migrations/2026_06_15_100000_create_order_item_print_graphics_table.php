<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_print_graphics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_print_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_work_form_id')->nullable()->constrained('order_item_work_forms')->nullOnDelete();
            $table->string('sequence_code')->nullable();
            $table->string('status')->default('waiting_visual');
            $table->string('customer_approval_status')->default('not_required');
            $table->foreignId('latest_attachment_id')->nullable()->constrained('order_item_work_form_attachments')->nullOnDelete();
            $table->text('graphic_note')->nullable();
            $table->text('customer_note')->nullable();
            $table->string('visibility_default')->nullable();
            $table->timestamp('production_ready_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('revision_requested_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_account_id', 'order_item_print_id'], 'oipg_tenant_print_unique');
            $table->index(['tenant_account_id', 'order_id'], 'oipg_tenant_order_index');
            $table->index(['tenant_account_id', 'order_item_id'], 'oipg_tenant_item_index');
            $table->index(['tenant_account_id', 'order_item_print_id'], 'oipg_tenant_print_index');
            $table->index('order_item_work_form_id', 'oipg_work_form_index');
            $table->index('status', 'oipg_status_index');
            $table->index('customer_approval_status', 'oipg_customer_approval_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_print_graphics');
    }
};
