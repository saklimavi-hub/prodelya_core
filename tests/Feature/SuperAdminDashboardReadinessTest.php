<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminDashboardReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    public function test_dashboard_shows_real_operational_notes_instead_of_empty_phase_fallbacks(): void
    {
        $platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        $response = $this->actingAs($platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'));

        $response->assertOk();
        $response->assertSee('Operasyon Notları');
        $response->assertSee('Queue / Scheduler');
        $response->assertSee('SaaS Cari / Hizmet');
        $response->assertSee('Başvuru / Paket Talep Geçmişi');
        $response->assertDontSee('Bu fazda hazır değil');
    }
}
