<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_category_mappings', function (Blueprint $table) {
            if (!Schema::hasColumn('supplier_category_mappings', 'supplier_category_code')) {
                $table->string('supplier_category_code')->nullable()->after('supplier_source_id');
            }

            if (!Schema::hasColumn('supplier_category_mappings', 'supplier_category_path')) {
                $table->string('supplier_category_path')->nullable()->after('source_category');
            }

            if (!Schema::hasColumn('supplier_category_mappings', 'supplier_category_level')) {
                $table->unsignedInteger('supplier_category_level')->nullable()->after('supplier_category_path');
            }

            if (!Schema::hasColumn('supplier_category_mappings', 'normalized_name')) {
                $table->string('normalized_name')->nullable()->after('supplier_category_level');
            }

            if (!Schema::hasColumn('supplier_category_mappings', 'product_count')) {
                $table->unsignedInteger('product_count')->default(0)->after('normalized_name');
            }

            if (!Schema::hasColumn('supplier_category_mappings', 'sample_product_names')) {
                $table->json('sample_product_names')->nullable()->after('product_count');
            }

            if (!Schema::hasColumn('supplier_category_mappings', 'sample_image_urls')) {
                $table->json('sample_image_urls')->nullable()->after('sample_product_names');
            }

            if (!Schema::hasColumn('supplier_category_mappings', 'suggestion_meta')) {
                $table->json('suggestion_meta')->nullable()->after('sample_image_urls');
            }

            if (!Schema::hasColumn('supplier_category_mappings', 'decision_type')) {
                $table->string('decision_type')->nullable()->after('mapping_status');
            }

            if (!Schema::hasColumn('supplier_category_mappings', 'decision_note')) {
                $table->text('decision_note')->nullable()->after('decision_type');
            }

            if (!Schema::hasColumn('supplier_category_mappings', 'last_scanned_at')) {
                $table->timestamp('last_scanned_at')->nullable()->after('reviewed_at');
            }
        });

        Schema::table('supplier_category_mappings', function (Blueprint $table) {
            $table->index(['supplier_id', 'normalized_name'], 'supplier_category_mappings_supplier_normalized_idx');
            $table->index(['mapping_status', 'decision_type'], 'supplier_category_mappings_status_decision_idx');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_category_mappings', function (Blueprint $table) {
            $table->dropIndex('supplier_category_mappings_supplier_normalized_idx');
            $table->dropIndex('supplier_category_mappings_status_decision_idx');

            $columns = array_filter([
                Schema::hasColumn('supplier_category_mappings', 'supplier_category_code') ? 'supplier_category_code' : null,
                Schema::hasColumn('supplier_category_mappings', 'supplier_category_path') ? 'supplier_category_path' : null,
                Schema::hasColumn('supplier_category_mappings', 'supplier_category_level') ? 'supplier_category_level' : null,
                Schema::hasColumn('supplier_category_mappings', 'normalized_name') ? 'normalized_name' : null,
                Schema::hasColumn('supplier_category_mappings', 'product_count') ? 'product_count' : null,
                Schema::hasColumn('supplier_category_mappings', 'sample_product_names') ? 'sample_product_names' : null,
                Schema::hasColumn('supplier_category_mappings', 'sample_image_urls') ? 'sample_image_urls' : null,
                Schema::hasColumn('supplier_category_mappings', 'suggestion_meta') ? 'suggestion_meta' : null,
                Schema::hasColumn('supplier_category_mappings', 'decision_type') ? 'decision_type' : null,
                Schema::hasColumn('supplier_category_mappings', 'decision_note') ? 'decision_note' : null,
                Schema::hasColumn('supplier_category_mappings', 'last_scanned_at') ? 'last_scanned_at' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
