<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'tenant_base_currency')) {
                $table->string('tenant_base_currency', 3)->nullable()->after('currency');
            }

            if (! Schema::hasColumn('orders', 'currency_policy')) {
                $table->string('currency_policy', 40)->nullable()->after('tenant_base_currency');
            }

            if (! Schema::hasColumn('orders', 'currency_snapshot_summary')) {
                $table->json('currency_snapshot_summary')->nullable()->after('currency_policy');
            }

            if (! Schema::hasColumn('orders', 'rates_refreshed_at')) {
                $table->timestamp('rates_refreshed_at')->nullable()->after('currency_snapshot_summary');
            }

            if (! Schema::hasColumn('orders', 'rates_refreshed_by')) {
                $table->foreignId('rates_refreshed_by')->nullable()->after('rates_refreshed_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('orders', 'current_rate_acknowledged_at')) {
                $table->timestamp('current_rate_acknowledged_at')->nullable()->after('rates_refreshed_by');
            }

            if (! Schema::hasColumn('orders', 'current_rate_acknowledged_by')) {
                $table->foreignId('current_rate_acknowledged_by')->nullable()->after('current_rate_acknowledged_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('orders', 'currency_snapshot_locked_at')) {
                $table->timestamp('currency_snapshot_locked_at')->nullable()->after('current_rate_acknowledged_by');
            }
        });

        Schema::table('order_item_prints', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_item_prints', 'pricing_snapshot')) {
                $table->json('pricing_snapshot')->nullable()->after('print_total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_item_prints', function (Blueprint $table): void {
            if (Schema::hasColumn('order_item_prints', 'pricing_snapshot')) {
                $table->dropColumn('pricing_snapshot');
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'currency_snapshot_locked_at')) {
                $table->dropColumn('currency_snapshot_locked_at');
            }

            if (Schema::hasColumn('orders', 'current_rate_acknowledged_by')) {
                $table->dropConstrainedForeignId('current_rate_acknowledged_by');
            }

            if (Schema::hasColumn('orders', 'current_rate_acknowledged_at')) {
                $table->dropColumn('current_rate_acknowledged_at');
            }

            if (Schema::hasColumn('orders', 'rates_refreshed_by')) {
                $table->dropConstrainedForeignId('rates_refreshed_by');
            }

            if (Schema::hasColumn('orders', 'rates_refreshed_at')) {
                $table->dropColumn('rates_refreshed_at');
            }

            if (Schema::hasColumn('orders', 'currency_snapshot_summary')) {
                $table->dropColumn('currency_snapshot_summary');
            }

            if (Schema::hasColumn('orders', 'currency_policy')) {
                $table->dropColumn('currency_policy');
            }

            if (Schema::hasColumn('orders', 'tenant_base_currency')) {
                $table->dropColumn('tenant_base_currency');
            }
        });
    }
};
