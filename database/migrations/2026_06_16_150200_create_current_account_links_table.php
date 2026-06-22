<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('current_account_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('current_account_id')->constrained('current_accounts')->cascadeOnDelete();
            $table->string('link_type', 60);
            $table->unsignedBigInteger('link_id');
            $table->boolean('is_primary')->default(false);
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->unique(['tenant_account_id', 'link_type', 'link_id'], 'cal_tenant_type_id_unique');
            $table->index(['tenant_account_id', 'current_account_id'], 'cal_tenant_account_idx');
            $table->index(['tenant_account_id', 'link_type'], 'cal_tenant_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('current_account_links');
    }
};
