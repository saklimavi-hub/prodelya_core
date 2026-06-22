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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->onDelete('cascade');
            $table->enum('order_family', ['promotion', 'print']);
            $table->enum('order_mode', ['product_sale_print', 'print_service_only'])->nullable();
            $table->enum('document_type', ['quote', 'order']);
            $table->string('document_number');
            $table->foreignId('source_quote_id')->nullable()->constrained('orders')->onDelete('set null');
            $table->foreignId('customer_company_id')->nullable()->constrained('companies')->onDelete('set null');
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected', 'cancelled', 'completed'])->default('draft');
            $table->string('workflow_status')->nullable();
            $table->string('currency', 3)->default('TL');
            $table->decimal('subtotal', 15, 2)->nullable();
            $table->decimal('vat_total', 15, 2)->nullable();
            $table->decimal('grand_total', 15, 2)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            
            $table->unique(['tenant_account_id', 'document_number']);
            $table->index(['tenant_account_id', 'document_type']);
            $table->index(['tenant_account_id', 'status']);
            $table->index(['customer_company_id', 'status']);
            $table->index(['order_family', 'document_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
