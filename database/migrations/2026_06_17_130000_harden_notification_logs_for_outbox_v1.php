<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('notification_logs', 'audience_type')) {
                $table->string('audience_type', 40)->nullable()->after('channel');
            }

            if (!Schema::hasColumn('notification_logs', 'template_id')) {
                $table->foreignId('template_id')->nullable()->after('notification_key')->constrained('notification_templates')->nullOnDelete();
            }

            if (!Schema::hasColumn('notification_logs', 'attempt_count')) {
                $table->integer('attempt_count')->default(0)->after('status');
            }

            if (!Schema::hasColumn('notification_logs', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable()->after('dispatch_mode');
            }

            if (!Schema::hasColumn('notification_logs', 'next_retry_at')) {
                $table->timestamp('next_retry_at')->nullable()->after('scheduled_at');
            }

            if (!Schema::hasColumn('notification_logs', 'provider_response')) {
                $table->json('provider_response')->nullable()->after('next_retry_at');
            }

            if (!Schema::hasColumn('notification_logs', 'response_code')) {
                $table->string('response_code', 60)->nullable()->after('provider_response');
            }

            if (!Schema::hasColumn('notification_logs', 'meta_json')) {
                $table->json('meta_json')->nullable()->after('response_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            foreach (['meta_json', 'response_code', 'provider_response', 'next_retry_at', 'scheduled_at', 'attempt_count', 'template_id', 'audience_type'] as $column) {
                if (Schema::hasColumn('notification_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
