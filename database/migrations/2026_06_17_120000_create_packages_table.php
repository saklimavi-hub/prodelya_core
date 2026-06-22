<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_public')->default(true);
            $table->integer('trial_days')->nullable();
            $table->decimal('monthly_price', 15, 2)->nullable();
            $table->decimal('yearly_price', 15, 2)->nullable();
            $table->string('currency')->default('TRY');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'is_public']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
