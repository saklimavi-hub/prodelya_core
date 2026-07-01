<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_upgrade_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained('tenant_accounts')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('request_type', 50);
            $table->string('status', 30)->default('pending');
            $table->string('current_package_key', 100)->nullable();
            $table->string('requested_package_key', 100)->nullable();
            $table->string('requested_module_key', 100)->nullable();
            $table->string('requested_feature_key', 100)->nullable();
            $table->string('requested_limit_key', 100)->nullable();
            $table->unsignedInteger('current_limit_value')->nullable();
            $table->unsignedInteger('requested_limit_value')->nullable();
            $table->foreignId('requested_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('requested_supplier_key', 100)->nullable();
            $table->string('requested_service_key', 100)->nullable();
            $table->text('requested_note')->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->index(['tenant_account_id', 'status']);
            $table->index(['tenant_account_id', 'request_type', 'status'], 'tur_tenant_type_status_idx');
            $table->index(['requested_package_key', 'status']);
            $table->index(['requested_module_key', 'status']);
            $table->index(['requested_feature_key', 'status']);
            $table->index(['requested_limit_key', 'status']);
            $table->index(['requested_supplier_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_upgrade_requests');
    }
};
