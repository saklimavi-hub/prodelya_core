<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_delivery_label_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained('tenant_accounts');
            $table->foreignId('order_id')->constrained('orders');
            $table->string('template_type', 40);
            $table->unsignedInteger('label_count');
            $table->unsignedInteger('page_count')->nullable();
            $table->decimal('roll_width_mm', 8, 2)->nullable();
            $table->decimal('roll_height_mm', 8, 2)->nullable();
            $table->decimal('roll_gap_mm', 8, 2)->nullable();
            $table->string('status', 40)->default('draft');
            $table->timestamp('printed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_account_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_delivery_label_batches');
    }
};
