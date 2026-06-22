<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_work_form_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_form_id')->constrained('order_item_work_forms')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->string('action_type');
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('attachment_id')->nullable()->constrained('order_item_work_form_attachments')->nullOnDelete();
            $table->string('visibility')->default('internal');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_account_id', 'work_form_id']);
            $table->index('work_form_id');
            $table->index('attachment_id');
            $table->index('visibility');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_work_form_activity_logs');
    }
};
