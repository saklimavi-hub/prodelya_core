<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_delivery_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained('tenant_accounts');
            $table->foreignId('order_id')->constrained('orders');
            $table->unsignedInteger('package_no');
            $table->string('package_label')->nullable();
            $table->string('package_type', 40)->default('box');
            $table->string('status', 40)->default('planned');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_account_id', 'order_id']);
            $table->unique(['tenant_account_id', 'order_id', 'package_no'], 'order_delivery_packages_unique_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_delivery_packages');
    }
};
