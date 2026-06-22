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
        Schema::create('accounting_export_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('accounting_integration_id')->constrained()->onDelete('cascade');
            $table->enum('entity_type', ['order', 'invoice', 'payment', 'customer', 'product']);
            $table->unsignedBigInteger('entity_id');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'retry'])->default('pending');
            $table->integer('retry_count')->default(0);
            $table->json('payload')->nullable(); // Data to be exported
            $table->json('response')->nullable(); // API response
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamps();
            
            $table->index(['tenant_account_id', 'status']);
            $table->index(['accounting_integration_id', 'status']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('retry_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_export_queue');
    }
};
