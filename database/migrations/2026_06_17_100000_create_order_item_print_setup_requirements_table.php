<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_print_setup_requirements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_account_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('order_item_print_id');
            $table->string('setup_type');
            $table->string('status')->default('bekliyor');
            $table->unsignedBigInteger('assigned_company_id')->nullable();
            $table->unsignedBigInteger('assigned_current_account_id')->nullable();
            $table->decimal('cost', 15, 2)->nullable();
            $table->string('currency')->nullable()->default('TRY');
            $table->text('note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_account_id', 'order_item_print_id', 'setup_type'],
                'oipsr_tenant_print_setup_unique'
            );
            $table->index(['tenant_account_id', 'order_item_print_id'], 'oipsr_tenant_print_idx');
            $table->index(['tenant_account_id', 'setup_type'], 'oipsr_tenant_setup_type_idx');
            $table->index(['tenant_account_id', 'status'], 'oipsr_tenant_status_idx');
            $table->index('order_id');
            $table->index('order_item_id');

            $table->foreign('tenant_account_id')->references('id')->on('tenant_accounts')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('order_item_id')->references('id')->on('order_items')->cascadeOnDelete();
            $table->foreign('order_item_print_id')->references('id')->on('order_item_prints')->cascadeOnDelete();
            $table->foreign('assigned_company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('assigned_current_account_id')->references('id')->on('current_accounts')->nullOnDelete();
            $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_print_setup_requirements');
    }
};
