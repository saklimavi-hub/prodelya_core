<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_delivery_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['tenant_account_id', 'name']);
            $table->unique(['tenant_account_id', 'code']);
            $table->index(['tenant_account_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_delivery_types');
    }
};
