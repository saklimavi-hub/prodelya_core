<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_provider_id')->constrained('payment_providers')->cascadeOnDelete();
            $table->foreignId('tenant_account_id')->nullable()->constrained('tenant_accounts')->cascadeOnDelete();
            $table->string('scope_type', 32)->default('super_admin_shared');
            $table->boolean('is_active')->default(true);
            $table->json('credentials_json')->nullable();
            $table->json('settings_json')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['payment_provider_id', 'tenant_account_id', 'scope_type'], 'pay_gateway_credential_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_credentials');
    }
};
