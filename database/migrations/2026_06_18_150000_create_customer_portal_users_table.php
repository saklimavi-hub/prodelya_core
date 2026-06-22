<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_portal_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_contact_id')->nullable()->constrained('company_contacts')->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('password');
            $table->string('status')->default('active');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip')->nullable();
            $table->rememberToken();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('tenant_account_id', 'cpu_tenant_index');
            $table->index('company_id', 'cpu_company_index');
            $table->index('company_contact_id', 'cpu_contact_index');
            $table->index('status', 'cpu_status_index');
            $table->unique(['tenant_account_id', 'email'], 'cpu_tenant_email_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_portal_users');
    }
};
