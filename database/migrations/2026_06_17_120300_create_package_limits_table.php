<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->string('limit_key');
            $table->integer('limit_value')->nullable();
            $table->boolean('is_unlimited')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['package_id', 'limit_key']);
            $table->index(['limit_key', 'is_unlimited']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_limits');
    }
};
