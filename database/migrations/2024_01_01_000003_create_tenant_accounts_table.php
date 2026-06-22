<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenant_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('slug')->unique();
            $table->string('panel_subdomain')->nullable()->unique();
            $table->string('custom_domain')->nullable()->unique();
            $table->string('portal_domain')->nullable()->unique();
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->string('package_key')->nullable();
            $table->string('default_locale', 10)->default('tr');
            $table->string('default_currency', 3)->default('TL');
            $table->string('timezone')->default('Europe/Istanbul');
            $table->string('number_format_locale', 10)->default('tr_TR');
            $table->timestamps();
            
            $table->index(['status', 'package_key']);
            $table->index('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_accounts');
    }
};
