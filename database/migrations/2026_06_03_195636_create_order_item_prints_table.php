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
        Schema::create('order_item_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_item_id')->constrained()->onDelete('cascade');
            $table->string('print_type')->nullable();
            $table->string('print_option')->nullable();
            $table->string('print_location')->nullable();
            $table->string('print_color')->nullable();
            $table->string('cliche_status')->nullable();
            $table->decimal('print_quantity', 15, 4)->nullable();
            $table->decimal('print_unit_price', 15, 4)->nullable();
            $table->decimal('print_total', 15, 4)->nullable();
            $table->text('note')->nullable();
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected', 'cancelled'])->nullable()->default('draft');
            $table->timestamps();
            
            $table->index(['order_id', 'status']);
            $table->index(['order_item_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_item_prints');
    }
};
