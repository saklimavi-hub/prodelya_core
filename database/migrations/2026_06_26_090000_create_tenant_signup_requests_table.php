<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_signup_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_type', 20);
            $table->string('company_name');
            $table->string('contact_name');
            $table->string('phone', 50);
            $table->string('email');
            $table->string('city', 100)->nullable();
            $table->string('sector', 100)->nullable();
            $table->foreignId('requested_package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->string('requested_package_key', 100)->nullable()->index();
            $table->json('requested_modules_json')->nullable();
            $table->unsignedInteger('expected_user_count')->nullable();
            $table->string('demo_topic')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('new')->index();
            $table->string('source', 50)->default('public_landing');
            $table->foreignId('converted_tenant_account_id')->nullable()->constrained('tenant_accounts')->nullOnDelete();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->index(['request_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_signup_requests');
    }
};
