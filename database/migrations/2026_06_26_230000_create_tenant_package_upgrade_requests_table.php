<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_package_upgrade_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('current_package_key', 100)->nullable();
            $table->string('requested_package_key', 100);
            $table->string('status', 30)->default('new');
            $table->text('request_note')->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->index(['tenant_account_id', 'status'], 'tenant_pkg_req_tenant_status_idx');
            $table->index(['requested_package_key', 'status'], 'tenant_pkg_req_package_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_package_upgrade_requests');
    }
};
