<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_portal_users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
            $table->timestamp('invited_at')->nullable()->after('last_login_ip');
            $table->string('invite_token')->nullable()->after('invited_at');
            $table->timestamp('invite_expires_at')->nullable()->after('invite_token');
            $table->string('password_reset_token')->nullable()->after('invite_expires_at');
            $table->timestamp('password_reset_expires_at')->nullable()->after('password_reset_token');
            $table->timestamp('password_set_at')->nullable()->after('password_reset_expires_at');

            $table->index('invite_token', 'cpu_invite_token_index');
            $table->index('password_reset_token', 'cpu_reset_token_index');
        });
    }

    public function down(): void
    {
        Schema::table('customer_portal_users', function (Blueprint $table): void {
            $table->dropIndex('cpu_invite_token_index');
            $table->dropIndex('cpu_reset_token_index');
            $table->dropColumn([
                'invited_at',
                'invite_token',
                'invite_expires_at',
                'password_reset_token',
                'password_reset_expires_at',
                'password_set_at',
            ]);
            $table->string('password')->nullable(false)->change();
        });
    }
};
