<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_data_hub_sync_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_run_id')->constrained('product_data_hub_sync_runs')->cascadeOnDelete();
            $table->foreignId('supplier_source_id')->constrained('supplier_sources')->cascadeOnDelete();
            $table->string('supplier_product_key')->nullable();
            $table->foreignId('standard_product_id')->nullable()->constrained('standard_products')->nullOnDelete();
            $table->string('change_type');
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['supplier_source_id', 'change_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_data_hub_sync_changes');
    }
};
