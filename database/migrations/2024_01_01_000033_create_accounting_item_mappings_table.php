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
        Schema::create('accounting_item_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('accounting_integration_id')->constrained()->onDelete('cascade');
            $table->foreignId('tenant_catalog_product_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('accounting_item_id')->nullable(); // ID in accounting system
            $table->string('accounting_item_code')->nullable(); // Item code in accounting system
            $table->string('accounting_item_type')->nullable(); // product, service, etc.
            $table->enum('sync_status', ['pending', 'synced', 'error', 'manual'])->default('pending');
            $table->timestamp('last_sync_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->json('mapping_data')->nullable(); // Additional mapping information
            $table->timestamps();
            
            $table->unique(['accounting_integration_id', 'tenant_catalog_product_id']);
            $table->index(['tenant_account_id', 'sync_status']);
            $table->index('accounting_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_item_mappings');
    }
};
