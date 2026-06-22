<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_supplier_purchase_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_source_id')->nullable()->constrained('supplier_sources')->nullOnDelete();
            $table->foreignId('tenant_catalog_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_name')->nullable();
            $table->string('product_code')->nullable();
            $table->string('product_name');
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_purchase_price', 15, 4)->nullable();
            $table->string('currency', 3)->default('TL');
            $table->boolean('vat_enabled')->default(false);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('total_amount', 15, 4)->default(0);
            $table->decimal('payable_amount', 15, 4)->default(0);
            $table->string('entry_type')->default('existing_stock');
            $table->string('payable_status')->default('none');
            $table->string('document_no')->nullable();
            $table->date('entry_date');
            $table->string('warehouse_code')->nullable();
            $table->string('location_code')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_account_id', 'entry_type']);
            $table->index(['supplier_id', 'payable_status']);
            $table->index(['tenant_catalog_product_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_supplier_purchase_entries');
    }
};
