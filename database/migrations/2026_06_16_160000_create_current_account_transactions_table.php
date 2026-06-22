<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('current_account_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('current_account_id')->constrained('current_accounts')->cascadeOnDelete();
            $table->string('transaction_type', 60);
            $table->string('source_type', 120)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('direction', 20);
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('TRY');
            $table->date('transaction_date')->nullable();
            $table->date('due_date')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 30)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->index(['tenant_account_id', 'current_account_id'], 'cat_tenant_account_idx');
            $table->index(['tenant_account_id', 'transaction_type'], 'cat_tenant_type_idx');
            $table->index(['tenant_account_id', 'direction'], 'cat_tenant_direction_idx');
            $table->index(['tenant_account_id', 'status'], 'cat_tenant_status_idx');
            $table->index(['tenant_account_id', 'transaction_date'], 'cat_tenant_date_idx');
            $table->index(['source_type', 'source_id'], 'cat_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('current_account_transactions');
    }
};
