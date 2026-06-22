<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_work_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained('tenant_accounts')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('work_form_id')->constrained('order_item_work_forms')->cascadeOnDelete();
            $table->string('folder_type');
            $table->string('storage_driver');
            $table->string('root_key')->nullable();
            $table->string('relative_path', 500);
            $table->string('display_path', 500);
            $table->string('physical_path', 1000)->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('last_checked_at')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_account_id', 'work_form_id', 'folder_type'], 'oiwf_tenant_work_form_folder_type_unique');
            $table->index(['tenant_account_id', 'order_id']);
            $table->index(['tenant_account_id', 'order_item_id']);
            $table->index(['tenant_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_work_folders');
    }
};
