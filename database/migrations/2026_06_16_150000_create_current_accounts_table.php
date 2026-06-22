<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('current_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->string('account_code')->nullable();
            $table->string('display_name');
            $table->string('legal_name')->nullable();
            $table->string('short_name')->nullable();
            $table->string('tax_office')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('tc_no', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('website')->nullable();
            $table->string('default_currency', 3)->nullable();
            $table->integer('payment_terms_days')->nullable();
            $table->decimal('risk_limit', 15, 2)->nullable();
            $table->string('risk_status')->nullable();
            $table->string('status', 30)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_account_id', 'display_name'], 'ca_tenant_display_name_idx');
            $table->index(['tenant_account_id', 'tax_number'], 'ca_tenant_tax_number_idx');
            $table->index(['tenant_account_id', 'status'], 'ca_tenant_status_idx');
            $table->unique(['tenant_account_id', 'account_code'], 'ca_tenant_account_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('current_accounts');
    }
};
