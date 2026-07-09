<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('revision_quote_id')->constrained('orders')->onDelete('cascade');
            $table->unsignedInteger('revision_number')->default(1);
            $table->string('status', 40)->default('draft');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->unique(['tenant_account_id', 'revision_quote_id'], 'order_revisions_tenant_quote_unique');
            $table->index(['tenant_account_id', 'order_id'], 'order_revisions_tenant_order_index');
            $table->index(['tenant_account_id', 'status'], 'order_revisions_tenant_status_index');
        });

        Schema::create('order_revision_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_revision_id')->constrained('order_revisions')->onDelete('cascade');
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignId('order_item_print_id')->nullable()->constrained('order_item_prints')->nullOnDelete();
            $table->string('change_group', 40);
            $table->string('field_key', 120);
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('decision', 40)->default('no_change');
            $table->string('apply_status', 40)->default('pending');
            $table->text('reason')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_account_id', 'order_revision_id'], 'order_revision_changes_tenant_revision_index');
            $table->index(['tenant_account_id', 'change_group'], 'order_revision_changes_tenant_group_index');
            $table->index(['tenant_account_id', 'decision'], 'order_revision_changes_tenant_decision_index');
            $table->index(['tenant_account_id', 'apply_status'], 'order_revision_changes_tenant_apply_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_revision_changes');
        Schema::dropIfExists('order_revisions');
    }
};
