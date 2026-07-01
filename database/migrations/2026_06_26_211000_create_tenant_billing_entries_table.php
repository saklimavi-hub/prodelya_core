<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_billing_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_service_definition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('package_key')->nullable();
            $table->enum('entry_type', ['package_fee', 'service_fee', 'collection', 'manual_debit', 'manual_credit']);
            $table->string('title');
            $table->text('note')->nullable();
            $table->string('reference_no')->nullable()->index();
            $table->enum('direction', ['debit', 'credit']);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('TRY');
            $table->date('entry_date')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_billing_entries');
    }
};
