<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'customer_approval_status')) {
                $table->string('customer_approval_status', 40)->nullable()->after('workflow_status');
            }

            if (!Schema::hasColumn('orders', 'last_sent_at')) {
                $table->timestamp('last_sent_at')->nullable()->after('customer_approval_status');
            }

            if (!Schema::hasColumn('orders', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_at');
            }

            if (!Schema::hasColumn('orders', 'revision_requested_at')) {
                $table->timestamp('revision_requested_at')->nullable()->after('rejected_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach ([
                'revision_requested_at',
                'rejected_at',
                'last_sent_at',
                'customer_approval_status',
            ] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
