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
        Schema::create('feed_sync_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feed_sync_log_id')->constrained()->onDelete('cascade');
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            $table->foreignId('supplier_source_id')->constrained()->onDelete('cascade');
            $table->string('source_product_id')->nullable();
            $table->string('error_type'); // validation, mapping, network, etc.
            $table->text('error_message');
            $table->json('error_data')->nullable(); // Raw data that caused error
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->boolean('is_resolved')->default(false);
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
            
            $table->index(['feed_sync_log_id', 'error_type']);
            $table->index(['supplier_id', 'is_resolved']);
            $table->index('severity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feed_sync_errors');
    }
};
