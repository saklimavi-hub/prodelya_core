<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graphic_approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_print_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_print_graphic_id')->constrained('order_item_print_graphics')->cascadeOnDelete();
            $table->foreignId('work_form_id')->nullable()->constrained('order_item_work_forms')->nullOnDelete();
            $table->foreignId('attachment_id')->constrained('order_item_work_form_attachments')->cascadeOnDelete();
            $table->foreignId('customer_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('token')->unique();
            $table->string('status')->default('waiting');
            $table->text('customer_note')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('revision_requested_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->index(['tenant_account_id', 'order_item_print_graphic_id'], 'gar_tenant_graphic_index');
            $table->index(['tenant_account_id', 'status'], 'gar_tenant_status_index');
            $table->index('attachment_id', 'gar_attachment_index');
            $table->index('order_id', 'gar_order_index');
            $table->index('work_form_id', 'gar_work_form_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graphic_approval_requests');
    }
};
