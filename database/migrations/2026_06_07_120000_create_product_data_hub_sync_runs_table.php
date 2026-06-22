<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_data_hub_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_source_id')->constrained('supplier_sources')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('run_type')->default('manual');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('status')->default('running');
            $table->unsignedInteger('records_read')->default(0);
            $table->unsignedInteger('products_created')->default(0);
            $table->unsignedInteger('products_updated')->default(0);
            $table->unsignedInteger('products_unchanged')->default(0);
            $table->unsignedInteger('products_missing_from_feed')->default(0);
            $table->unsignedInteger('products_inactivated')->default(0);
            $table->unsignedInteger('price_changed_count')->default(0);
            $table->unsignedInteger('stock_changed_count')->default(0);
            $table->unsignedInteger('image_changed_count')->default(0);
            $table->unsignedInteger('category_changed_count')->default(0);
            $table->unsignedInteger('name_changed_count')->default(0);
            $table->unsignedInteger('description_changed_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('report_payload')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['supplier_source_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_data_hub_sync_runs');
    }
};
