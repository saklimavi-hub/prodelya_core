<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standard_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('standard_categories', 'canonical_category_id')) {
                $table->foreignId('canonical_category_id')
                    ->nullable()
                    ->after('parent_id')
                    ->constrained('standard_categories')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('standard_categories', 'duplicate_status')) {
                $table->string('duplicate_status')->default('clean')->after('requires_mapping');
            }
        });
    }

    public function down(): void
    {
        Schema::table('standard_categories', function (Blueprint $table) {
            if (Schema::hasColumn('standard_categories', 'canonical_category_id')) {
                $table->dropConstrainedForeignId('canonical_category_id');
            }

            if (Schema::hasColumn('standard_categories', 'duplicate_status')) {
                $table->dropColumn('duplicate_status');
            }
        });
    }
};
