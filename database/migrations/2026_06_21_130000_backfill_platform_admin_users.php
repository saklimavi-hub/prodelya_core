<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_platform_admin')) {
            return;
        }

        DB::table('users')
            ->whereIn(DB::raw('LOWER(email)'), [
                'admin@prodelya.local',
                'br.akyol@gmail.com',
            ])
            ->update([
                'is_platform_admin' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'is_platform_admin')) {
            return;
        }

        DB::table('users')
            ->whereIn(DB::raw('LOWER(email)'), [
                'admin@prodelya.local',
                'br.akyol@gmail.com',
            ])
            ->update([
                'is_platform_admin' => false,
                'updated_at' => now(),
            ]);
    }
};
