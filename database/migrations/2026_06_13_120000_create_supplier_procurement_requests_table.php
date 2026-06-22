<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_procurement_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('request_number');
            $table->date('request_date');
            $table->string('status', 40)->default('taslak');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_account_id', 'request_number'], 'spr_tenant_request_number_unique');
            $table->index(['tenant_account_id', 'supplier_id'], 'spr_tenant_supplier_idx');
            $table->index(['tenant_account_id', 'status'], 'spr_tenant_status_idx');
            $table->index(['tenant_account_id', 'request_date'], 'spr_tenant_request_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_procurement_requests');
    }
};
