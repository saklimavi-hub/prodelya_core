<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standard_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('standard_categories', 'requires_mapping')) {
                $table->boolean('requires_mapping')->default(true)->after('visible_in_catalog');
            }
        });
    }

    public function down(): void
    {
        Schema::table('standard_categories', function (Blueprint $table) {
            if (Schema::hasColumn('standard_categories', 'requires_mapping')) {
                $table->dropColumn('requires_mapping');
            }
        });
    }
};
