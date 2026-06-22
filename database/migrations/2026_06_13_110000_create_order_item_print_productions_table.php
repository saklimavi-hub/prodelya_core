<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_print_productions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_print_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_form_id')->nullable()->constrained('order_item_work_forms')->nullOnDelete();
            $table->string('production_type')->nullable();
            $table->string('production_status')->default('uretim_bekliyor');
            $table->foreignId('production_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('production_unit_name')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('planned_quantity', 12, 4);
            $table->decimal('completed_quantity', 12, 4)->default(0);
            $table->decimal('remaining_quantity', 12, 4)->default(0);
            $table->boolean('cliche_required')->default(false);
            $table->string('cliche_status')->nullable();
            $table->string('qc_status')->nullable();
            $table->text('production_note')->nullable();
            $table->text('issue_note')->nullable();
            $table->json('production_snapshot')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('sent_to_subcontractor_at')->nullable();
            $table->timestamp('returned_from_subcontractor_at')->nullable();
            $table->timestamp('qc_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_account_id', 'order_item_print_id'], 'oipp_tenant_print_unique');
            $table->index(['tenant_account_id', 'order_id'], 'oipp_tenant_order_index');
            $table->index(['tenant_account_id', 'order_item_id'], 'oipp_tenant_item_index');
            $table->index(['tenant_account_id', 'production_status'], 'oipp_tenant_status_index');
            $table->index(['tenant_account_id', 'production_type'], 'oipp_tenant_type_index');
            $table->index('production_company_id');
            $table->index('work_form_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_print_productions');
    }
};
