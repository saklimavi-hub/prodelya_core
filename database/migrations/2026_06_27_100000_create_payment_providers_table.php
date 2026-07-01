<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_key')->unique();
            $table->string('driver_key');
            $table->string('display_name');
            $table->string('status', 32)->default('draft');
            $table->string('checkout_mode', 32)->default('hosted');
            $table->boolean('supports_shared_saas_payments')->default(true);
            $table->boolean('supports_tenant_module')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_providers');
    }
};
