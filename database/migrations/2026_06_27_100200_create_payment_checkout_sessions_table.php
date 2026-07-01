<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_checkout_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_provider_id')->constrained('payment_providers')->cascadeOnDelete();
            $table->foreignId('payment_gateway_credential_id')->nullable()->constrained('payment_gateway_credentials')->nullOnDelete();
            $table->foreignId('tenant_account_id')->nullable()->constrained('tenant_accounts')->cascadeOnDelete();
            $table->string('scope_type', 32)->default('super_admin_shared');
            $table->string('payment_context', 64)->default('saas_billing');
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('reference_no')->unique();
            $table->string('status', 32)->default('draft');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 8)->default('TRY');
            $table->string('external_reference')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->text('checkout_url')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('provider_payload_json')->nullable();
            $table->json('meta_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'payment_checkout_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_checkout_sessions');
    }
};
