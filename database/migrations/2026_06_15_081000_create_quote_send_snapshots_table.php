<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_send_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('quote_id')->constrained('orders')->onDelete('cascade');
            $table->unsignedInteger('send_no');
            $table->longText('snapshot_json');
            $table->longText('summary_json')->nullable();
            $table->longText('financial_snapshot_json')->nullable();
            $table->string('sent_channel', 40)->nullable();
            $table->string('sent_to_name')->nullable();
            $table->string('sent_to_email')->nullable();
            $table->string('sent_to_phone')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_account_id', 'quote_id', 'send_no'], 'quote_send_snapshots_tenant_quote_send_unique');
            $table->index(['tenant_account_id', 'quote_id'], 'quote_send_snapshots_tenant_quote_index');
            $table->index(['tenant_account_id', 'sent_at'], 'quote_send_snapshots_tenant_sent_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_send_snapshots');
    }
};
