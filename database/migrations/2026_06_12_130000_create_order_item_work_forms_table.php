<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_work_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_quote_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('source_quote_number')->nullable();
            $table->string('work_form_number');
            $table->unsignedInteger('item_sequence');
            $table->string('status')->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->string('public_tracking_token', 120);
            $table->json('order_snapshot')->nullable();
            $table->json('customer_snapshot')->nullable();
            $table->json('product_snapshot')->nullable();
            $table->json('print_snapshot')->nullable();
            $table->json('graphic_snapshot')->nullable();
            $table->json('production_snapshot')->nullable();
            $table->json('delivery_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('last_rendered_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_account_id', 'work_form_number']);
            $table->unique('public_tracking_token');
            $table->unique('order_item_id');
            $table->index('order_id');
            $table->index(['tenant_account_id', 'order_id']);
            $table->index(['tenant_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_work_forms');
    }
};
