<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_twin_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('canonical_category_id')->constrained('standard_categories')->cascadeOnDelete();
            $table->foreignId('visible_parent_category_id')->constrained('standard_categories')->cascadeOnDelete();
            $table->string('display_name')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_twin_views');
    }
};
