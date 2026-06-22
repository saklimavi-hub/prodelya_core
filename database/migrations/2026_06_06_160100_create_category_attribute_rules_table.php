<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_attribute_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('standard_category_id')->constrained('standard_categories')->cascadeOnDelete();
            $table->foreignId('product_attribute_definition_id')->constrained('product_attribute_definitions')->cascadeOnDelete();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_filterable')->default(true);
            $table->boolean('visible_in_catalog')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['standard_category_id', 'product_attribute_definition_id'],
                'category_attribute_rules_category_attribute_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_attribute_rules');
    }
};
