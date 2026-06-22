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
        Schema::create('feed_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            $table->foreignId('supplier_source_id')->constrained()->onDelete('cascade');
            $table->enum('sync_type', ['full', 'incremental', 'manual']);
            $table->enum('status', ['running', 'completed', 'failed', 'cancelled'])->default('running');
            $table->integer('total_records')->default(0);
            $table->integer('processed_records')->default(0);
            $table->integer('error_records')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('error_summary')->nullable();
            $table->json('sync_metadata')->nullable(); // Sync statistics and metadata
            $table->timestamps();
            
            $table->index(['supplier_id', 'status']);
            $table->index(['supplier_source_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feed_sync_logs');
    }
};
