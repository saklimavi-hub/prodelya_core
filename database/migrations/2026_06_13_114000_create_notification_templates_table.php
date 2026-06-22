<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->nullable()->constrained('tenant_accounts')->nullOnDelete();
            $table->string('notification_key', 120);
            $table->string('channel', 40);
            $table->string('audience_type', 40);
            $table->string('title')->nullable();
            $table->string('subject')->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->json('variables_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_account_id', 'notification_key', 'channel', 'audience_type'], 'notif_templates_unique');
            $table->index(['tenant_account_id', 'channel']);
            $table->index(['tenant_account_id', 'audience_type']);
            $table->index(['tenant_account_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
