<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_category_mappings', function (Blueprint $table) {
            if (!Schema::hasColumn('supplier_category_mappings', 'standard_category_id')) {
                $table->foreignId('standard_category_id')->nullable()->constrained('standard_categories')->nullOnDelete()->after('supplier_source_id');
            }

            if (!Schema::hasColumn('supplier_category_mappings', 'mapping_status')) {
                $table->string('mapping_status')->nullable()->default('pending')->after('target_category');
            }

            if (!Schema::hasColumn('supplier_category_mappings', 'confidence_score')) {
                $table->decimal('confidence_score', 5, 2)->nullable()->after('mapping_status');
            }

            if (!Schema::hasColumn('supplier_category_mappings', 'reviewed_by')) {
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete()->after('confidence_score');
            }

            if (!Schema::hasColumn('supplier_category_mappings', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplier_category_mappings', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_category_mappings', 'standard_category_id')) {
                $table->dropConstrainedForeignId('standard_category_id');
            }

            if (Schema::hasColumn('supplier_category_mappings', 'reviewed_by')) {
                $table->dropConstrainedForeignId('reviewed_by');
            }

            $columns = array_filter([
                Schema::hasColumn('supplier_category_mappings', 'mapping_status') ? 'mapping_status' : null,
                Schema::hasColumn('supplier_category_mappings', 'confidence_score') ? 'confidence_score' : null,
                Schema::hasColumn('supplier_category_mappings', 'reviewed_at') ? 'reviewed_at' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
