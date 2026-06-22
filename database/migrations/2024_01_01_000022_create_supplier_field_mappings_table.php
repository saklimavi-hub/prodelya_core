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
        Schema::create('supplier_field_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            $table->foreignId('supplier_source_id')->constrained()->onDelete('cascade');
            $table->string('source_field'); // Field name in supplier data
            $table->string('target_field'); // Field name in our system
            $table->enum('field_type', ['text', 'number', 'decimal', 'date', 'boolean', 'json']);
            $table->json('transformation_rules')->nullable(); // Data transformation rules
            $table->boolean('is_required')->default(false);
            $table->text('default_value')->nullable();
            $table->timestamps();
            
            $table->unique(['supplier_source_id', 'source_field']);
            $table->index(['supplier_id', 'target_field']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_field_mappings');
    }
};
