<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_field_mappings', function (Blueprint $table) {
            $table->string('target_field')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_field_mappings', function (Blueprint $table) {
            $table->string('target_field')->nullable(false)->change();
        });
    }
};
