<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('payment_type', 30)->default('tahsilat');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('TL');
            $table->string('payment_method', 30)->nullable();
            $table->text('payment_note')->nullable();
            $table->string('payment_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_account_id', 'order_id']);
            $table->index(['tenant_account_id', 'customer_company_id']);
            $table->index(['tenant_account_id', 'payment_type']);
            $table->index(['tenant_account_id', 'payment_method']);
            $table->index(['tenant_account_id', 'paid_at']);
            $table->index(['tenant_account_id', 'due_date']);
            $table->index(['tenant_account_id', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
