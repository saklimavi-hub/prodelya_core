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
        Schema::create('standard_products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('brand')->nullable();
            $table->string('unit')->default('adet');
            $table->decimal('weight', 10, 3)->nullable(); // kg
            $table->decimal('length', 8, 2)->nullable(); // cm
            $table->decimal('width', 8, 2)->nullable(); // cm
            $table->decimal('height', 8, 2)->nullable(); // cm
            $table->json('attributes')->nullable(); // Product attributes like color, size, etc.
            $table->json('images')->nullable(); // Image URLs
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['sku', 'is_active']);
            $table->index('category');
            $table->index('brand');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('standard_products');
    }
};
