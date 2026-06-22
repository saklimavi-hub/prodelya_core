<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('accounting_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->onDelete('cascade');
            $table->enum('provider', ['parasut', 'mikro', 'logo', 'netsis', 'other']);
            $table->string('provider_name')->nullable(); // Custom provider name
            $table->enum('status', ['active', 'inactive', 'error', 'testing'])->default('inactive');
            $table->json('config')->nullable(); // API keys, endpoints, etc.
            $table->json('settings')->nullable(); // Integration-specific settings
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            
            $table->unique(['tenant_account_id', 'provider']);
            $table->index(['tenant_account_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_integrations');
    }
};
