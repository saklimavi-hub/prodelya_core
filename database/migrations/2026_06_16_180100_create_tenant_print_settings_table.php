<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_print_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('standard_print_type_id')->constrained('standard_print_types')->cascadeOnDelete();
            $table->string('custom_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('production_mode')->default('both');
            $table->foreignId('default_subcontractor_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('default_subcontractor_current_account_id')->nullable()->constrained('current_accounts')->nullOnDelete();
            $table->string('default_currency', 3)->nullable()->default('TRY');
            $table->decimal('default_unit_price', 15, 2)->nullable();
            $table->decimal('default_setup_cost', 15, 2)->nullable();
            $table->boolean('requires_graphic')->default(true);
            $table->boolean('requires_production')->default(true);
            $table->boolean('requires_setup')->default(false);
            $table->json('setup_types')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_account_id', 'standard_print_type_id'], 'tenant_print_settings_tenant_standard_unique');
            $table->index(['tenant_account_id', 'is_active']);
            $table->index(['tenant_account_id', 'production_mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_print_settings');
    }
};
