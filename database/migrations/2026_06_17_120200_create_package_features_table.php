<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->string('module_key')->nullable();
            $table->string('feature_key');
            $table->boolean('is_enabled')->default(true);
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['package_id', 'feature_key']);
            $table->index(['module_key', 'feature_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_features');
    }
};
