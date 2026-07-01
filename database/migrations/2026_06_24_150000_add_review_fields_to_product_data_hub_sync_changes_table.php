<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_data_hub_sync_changes')) {
            return;
        }

        Schema::table('product_data_hub_sync_changes', function (Blueprint $table) {
            if (!Schema::hasColumn('product_data_hub_sync_changes', 'review_status')) {
                $table->string('review_status')->nullable()->after('message');
            }

            if (!Schema::hasColumn('product_data_hub_sync_changes', 'review_payload')) {
                $table->json('review_payload')->nullable()->after('review_status');
            }

            if (!Schema::hasColumn('product_data_hub_sync_changes', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('review_payload');
            }

            if (!Schema::hasColumn('product_data_hub_sync_changes', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable()->after('reviewed_at');
            }

            if (!Schema::hasColumn('product_data_hub_sync_changes', 'missing_feed_run_count')) {
                $table->unsignedInteger('missing_feed_run_count')->default(0)->after('resolved_at');
            }

            if (!Schema::hasColumn('product_data_hub_sync_changes', 'is_passive_candidate')) {
                $table->boolean('is_passive_candidate')->default(false)->after('missing_feed_run_count');
            }
        });

        Schema::table('product_data_hub_sync_changes', function (Blueprint $table) {
            $table->index(['supplier_source_id', 'review_status'], 'pdh_sync_changes_source_review_status_idx');
            $table->index(['supplier_source_id', 'is_passive_candidate'], 'pdh_sync_changes_source_passive_candidate_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('product_data_hub_sync_changes')) {
            return;
        }

        Schema::table('product_data_hub_sync_changes', function (Blueprint $table) {
            $table->dropIndex('pdh_sync_changes_source_review_status_idx');
            $table->dropIndex('pdh_sync_changes_source_passive_candidate_idx');
        });

        Schema::table('product_data_hub_sync_changes', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('product_data_hub_sync_changes', 'review_status') ? 'review_status' : null,
                Schema::hasColumn('product_data_hub_sync_changes', 'review_payload') ? 'review_payload' : null,
                Schema::hasColumn('product_data_hub_sync_changes', 'reviewed_at') ? 'reviewed_at' : null,
                Schema::hasColumn('product_data_hub_sync_changes', 'resolved_at') ? 'resolved_at' : null,
                Schema::hasColumn('product_data_hub_sync_changes', 'missing_feed_run_count') ? 'missing_feed_run_count' : null,
                Schema::hasColumn('product_data_hub_sync_changes', 'is_passive_candidate') ? 'is_passive_candidate' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
