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
        Schema::create('tenant_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->onDelete('cascade');
            $table->enum('document_type', ['quote', 'order']);
            $table->enum('order_family', ['promotion', 'print'])->nullable();
            $table->integer('year');
            $table->integer('month')->nullable();
            $table->string('prefix');
            $table->integer('sequence_length')->default(4);
            $table->integer('current_number')->default(0);
            $table->enum('reset_period', ['yearly', 'monthly', 'never'])->default('yearly');
            $table->string('format');
            $table->timestamps();
            
            $table->unique(['tenant_account_id', 'document_type', 'order_family', 'year', 'month']);
            $table->index(['tenant_account_id', 'document_type']);
            $table->index(['tenant_account_id', 'order_family']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_number_sequences');
    }
};
