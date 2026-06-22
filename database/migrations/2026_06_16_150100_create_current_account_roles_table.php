<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('current_account_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('current_account_id')->constrained('current_accounts')->cascadeOnDelete();
            $table->string('role', 40);
            $table->boolean('is_primary')->default(false);
            $table->string('status', 30)->default('active');
            $table->timestamps();

            $table->unique(['tenant_account_id', 'current_account_id', 'role'], 'car_tenant_account_role_unique');
            $table->index(['tenant_account_id', 'role'], 'car_tenant_role_idx');
            $table->index(['tenant_account_id', 'status'], 'car_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('current_account_roles');
    }
};
