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
        Schema::create('tenant_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->onDelete('cascade');
            $table->string('module_key');
            $table->string('feature_key')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->integer('limit_value')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            
            $table->unique(['tenant_account_id', 'module_key', 'feature_key']);
            $table->index(['module_key', 'is_enabled']);
            $table->index(['tenant_account_id', 'is_enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_modules');
    }
};
