<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('quote_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('quote_send_snapshot_id')->constrained('quote_send_snapshots')->onDelete('cascade');
            $table->foreignId('customer_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('token')->unique();
            $table->string('status', 40)->default('waiting');
            $table->text('customer_note')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_account_id', 'quote_id'], 'quote_approval_requests_tenant_quote_index');
            $table->index(['tenant_account_id', 'quote_send_snapshot_id'], 'quote_approval_requests_tenant_snapshot_index');
            $table->index(['tenant_account_id', 'status'], 'quote_approval_requests_tenant_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_approval_requests');
    }
};
