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
        Schema::create('accounting_export_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('accounting_integration_id')->constrained()->onDelete('cascade');
            $table->enum('log_type', ['sync', 'export', 'import', 'error', 'info']);
            $table->string('level'); // debug, info, warning, error, critical
            $table->text('message');
            $table->json('context')->nullable(); // Additional context data
            $table->string('request_id')->nullable(); // For tracking request chains
            $table->integer('duration_ms')->nullable(); // Operation duration
            $table->timestamps();
            
            $table->index(['tenant_account_id', 'log_type']);
            $table->index(['accounting_integration_id', 'level']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_export_logs');
    }
};
