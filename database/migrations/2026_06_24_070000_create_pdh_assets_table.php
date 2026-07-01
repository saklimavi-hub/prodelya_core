<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdh_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_account_id')->nullable()->constrained('tenant_accounts')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('supplier_sources')->nullOnDelete();
            $table->foreignId('sync_run_id')->nullable()->constrained('product_data_hub_sync_runs')->nullOnDelete();
            $table->foreignId('standard_product_id')->nullable()->constrained('standard_products')->nullOnDelete();
            $table->foreignId('standard_product_variant_id')->nullable()->constrained('standard_product_variants')->nullOnDelete();
            $table->foreignId('tenant_catalog_product_id')->nullable()->constrained('tenant_catalog_products')->nullOnDelete();
            $table->foreignId('tenant_catalog_product_variant_id')->nullable()->constrained('tenant_catalog_product_variants')->nullOnDelete();
            $table->string('asset_type');
            $table->string('disk');
            $table->string('object_key');
            $table->text('original_url')->nullable();
            $table->text('public_url')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('extension', 32)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum_sha256', 128)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('visibility', 16)->default('private');
            $table->string('status', 32)->default('pending');
            $table->string('storage_provider', 32)->nullable();
            $table->timestamp('stored_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('failed_reason')->nullable();
            $table->timestamps();

            $table->index('asset_type');
            $table->index('status');
            $table->index('disk');
            $table->index('source_id');
            $table->index('supplier_id');
            $table->index('sync_run_id');
            $table->index('tenant_account_id');
            $table->index('standard_product_id');
            $table->index('tenant_catalog_product_id');
            $table->index('checksum_sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdh_assets');
    }
};
