<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standard_print_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('production_family')->nullable();
            $table->boolean('default_requires_graphic')->default(true);
            $table->boolean('default_requires_production')->default(true);
            $table->boolean('default_requires_setup')->default(false);
            $table->json('default_setup_types')->nullable();
            $table->string('default_production_mode')->default('both');
            $table->string('status')->default('active');
            $table->integer('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'sort_order']);
            $table->index(['production_family', 'status']);
            $table->index('default_production_mode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standard_print_types');
    }
};
