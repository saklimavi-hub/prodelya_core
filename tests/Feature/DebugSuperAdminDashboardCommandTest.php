<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebugSuperAdminDashboardCommandTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_debug_super_admin_dashboard_command_reports_context_and_render_successfully(): void
    {
        $this->artisan('prodelya:debug-super-admin-dashboard')
            ->expectsOutputToContain('Super Admin dashboard debug başlıyor.')
            ->expectsOutputToContain('Summary service çalıştı.')
            ->expectsOutputToContain('Operation dashboard service çalıştı.')
            ->expectsOutputToContain('Blade render başarılı.')
            ->expectsOutputToContain('Super Admin dashboard debug tamamlandı.')
            ->assertSuccessful();
    }
}
