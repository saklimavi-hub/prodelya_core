<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_provider_id')->constrained('payment_providers')->cascadeOnDelete();
            $table->foreignId('tenant_account_id')->nullable()->constrained('tenant_accounts')->cascadeOnDelete();
            $table->string('scope_type', 32)->default('super_admin_shared');
            $table->string('event_key', 120);
            $table->string('status', 32)->default('received');
            $table->string('external_reference')->nullable();
            $table->json('headers_json')->nullable();
            $table->json('payload_json')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_logs');
    }
};
