<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_field_mappings', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_field_mappings', 'standard_field_key')) {
                $table->dropColumn('standard_field_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplier_field_mappings', function (Blueprint $table) {
            if (!Schema::hasColumn('supplier_field_mappings', 'standard_field_key')) {
                $table->string('standard_field_key')->nullable()->after('legacy_field_name');
            }
        });
    }
};
