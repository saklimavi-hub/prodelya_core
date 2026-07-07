<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_print_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained('tenant_accounts')->cascadeOnDelete();
            $table->foreignId('tenant_print_setting_id')->constrained('tenant_print_settings')->cascadeOnDelete();
            $table->foreignId('standard_print_type_id')->nullable()->constrained('standard_print_types')->nullOnDelete();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->decimal('default_unit_price', 12, 4)->nullable();
            $table->boolean('requires_setup')->default(false);
            $table->string('setup_type', 100)->nullable();
            $table->string('setup_status_default', 100)->nullable();
            $table->timestamps();

            $table->unique(['tenant_account_id', 'tenant_print_setting_id', 'code'], 'tenant_print_options_tenant_setting_code_unique');
            $table->index(['tenant_account_id', 'tenant_print_setting_id', 'is_active'], 'tenant_print_options_tenant_setting_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_print_options');
    }
};
