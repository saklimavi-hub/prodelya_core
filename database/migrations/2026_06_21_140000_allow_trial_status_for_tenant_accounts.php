<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE tenant_accounts MODIFY status ENUM('active','trial','inactive','suspended') NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE tenant_accounts MODIFY status ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active'");
    }
};
