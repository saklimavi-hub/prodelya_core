<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE tenant_number_sequences MODIFY document_type VARCHAR(50) NOT NULL");
            return;
        }

        Schema::table('tenant_number_sequences', function (Blueprint $table) {
            $table->string('document_type', 50)->change();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE tenant_number_sequences MODIFY document_type ENUM('quote','order') NOT NULL");
            return;
        }

        Schema::table('tenant_number_sequences', function (Blueprint $table) {
            $table->enum('document_type', ['quote', 'order'])->change();
        });
    }
};
