<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('standard_categories')) {
            return;
        }

        Schema::create('standard_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('standard_categories')->nullOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('product_family')->default('promotion');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->integer('depth')->default(0);
            $table->string('path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('visible_in_catalog')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['product_family', 'is_active']);
            $table->index(['parent_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standard_categories');
    }
};
