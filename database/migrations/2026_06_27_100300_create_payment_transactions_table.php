<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_checkout_session_id')->constrained('payment_checkout_sessions')->cascadeOnDelete();
            $table->foreignId('payment_provider_id')->constrained('payment_providers')->cascadeOnDelete();
            $table->foreignId('tenant_account_id')->nullable()->constrained('tenant_accounts')->cascadeOnDelete();
            $table->string('transaction_type', 32)->default('checkout_initialized');
            $table->string('status', 32)->default('pending');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 8)->default('TRY');
            $table->string('external_reference')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->json('provider_payload_json')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
