<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('standard_category_id')->constrained('standard_categories')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('alias_name');
            $table->string('normalized_alias');
            $table->string('source_type')->default('manual');
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['normalized_alias', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_aliases');
    }
};
