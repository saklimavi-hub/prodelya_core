<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_field_mappings', function (Blueprint $table) {
            if (!Schema::hasColumn('supplier_field_mappings', 'tenant_account_id')) {
                $table->foreignId('tenant_account_id')->nullable()->after('id')->constrained('tenant_accounts')->nullOnDelete();
            }

            if (!Schema::hasColumn('supplier_field_mappings', 'legacy_field_name')) {
                $table->string('legacy_field_name')->nullable()->after('source_field');
            }

            if (!Schema::hasColumn('supplier_field_mappings', 'mapping_status')) {
                $table->string('mapping_status')->default('pending')->after('target_field');
            }

            if (!Schema::hasColumn('supplier_field_mappings', 'confidence_score')) {
                $table->decimal('confidence_score', 5, 2)->nullable()->after('mapping_status');
            }

            if (!Schema::hasColumn('supplier_field_mappings', 'transform_rule')) {
                $table->string('transform_rule')->nullable()->after('field_type');
            }

            if (!Schema::hasColumn('supplier_field_mappings', 'note')) {
                $table->text('note')->nullable()->after('transform_rule');
            }

            if (!Schema::hasColumn('supplier_field_mappings', 'reviewed_by')) {
                $table->foreignId('reviewed_by')->nullable()->after('note')->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('supplier_field_mappings', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            }

            if (!Schema::hasColumn('supplier_field_mappings', 'meta')) {
                $table->json('meta')->nullable()->after('reviewed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplier_field_mappings', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_field_mappings', 'tenant_account_id')) {
                $table->dropConstrainedForeignId('tenant_account_id');
            }

            if (Schema::hasColumn('supplier_field_mappings', 'reviewed_by')) {
                $table->dropConstrainedForeignId('reviewed_by');
            }

            foreach ([
                'legacy_field_name',
                'mapping_status',
                'confidence_score',
                'transform_rule',
                'note',
                'reviewed_at',
                'meta',
            ] as $column) {
                if (Schema::hasColumn('supplier_field_mappings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
