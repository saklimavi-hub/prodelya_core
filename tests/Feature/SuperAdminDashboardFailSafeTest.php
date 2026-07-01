<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use App\Services\SuperAdmin\SuperAdminOperationDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Mockery;
use Tests\TestCase;

class SuperAdminDashboardFailSafeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private TenantAccount $tenant;
    private Role $tenantAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenantAdminRole = Role::query()->where('key', 'admin')->firstOrFail();
    }

    public function test_super_admin_dashboard_returns_200(): void
    {
        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'))
            ->assertOk()
            ->assertSee('Super Admin Operasyon Merkezi');
    }

    public function test_super_admin_dashboard_minimal_mode_returns_plain_text(): void
    {
        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard', ['minimal' => 1]))
            ->assertOk()
            ->assertSeeText('Super Admin Dashboard controller çalışıyor.');
    }

    public function test_dashboard_returns_200_when_operation_dashboard_service_throws(): void
    {
        $mock = Mockery::mock(SuperAdminOperationDashboardService::class);
        $mock->shouldReceive('buildDashboardContext')->andThrow(new \RuntimeException('forced dashboard failure'));
        $this->app->instance(SuperAdminOperationDashboardService::class, $mock);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.dashboard'));

        $response->assertOk();
        $response->assertSee('Operasyon paneli verisi hazırlanamadı');
        $response->assertSee('Bilinmiyor');
    }

    public function test_operation_dashboard_service_falls_back_when_product_data_hub_section_throws(): void
    {
        $service = $this->partialOperationDashboardService();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('buildProductDataHubSection')->andThrow(new \RuntimeException('forced pdh failure'));

        $context = $service->buildDashboardContext();

        $this->assertSame([], data_get($context, 'product_data_hub.rows', []));
        $this->assertSame('Bu bölüm için veri hazırlanamadı.', data_get($context, 'product_data_hub.message'));
        $this->assertNotEmpty(data_get($context, 'product_data_hub.warnings', []));
        $this->assertIsArray(data_get($context, 'kpis.cards', []));
    }

    public function test_operation_dashboard_service_falls_back_when_system_health_section_throws(): void
    {
        $service = $this->partialOperationDashboardService();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('buildSystemHealthSection')->andThrow(new \RuntimeException('forced system health failure'));

        $context = $service->buildDashboardContext();

        $this->assertSame('Bu bölüm için veri hazırlanamadı.', data_get($context, 'system_health.message'));
        $this->assertSame('Bilinmiyor', data_get($context, 'system_health.queue_worker.status_label'));
        $this->assertSame('Bu bölüm için veri hazırlanamadı.', data_get($context, 'system_health.queue_worker.description'));
    }

    public function test_operation_dashboard_service_falls_back_when_security_warnings_section_throws(): void
    {
        $service = $this->partialOperationDashboardService();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('buildSecurityWarningsSection')->andThrow(new \RuntimeException('forced notification failure'));

        $context = $service->buildDashboardContext();

        $this->assertSame('Operasyon paneli bölümü fallback modunda açıldı', data_get($context, 'security_warnings.0.title'));
        $this->assertSame('Bu bölüm için veri hazırlanamadı.', data_get($context, 'security_warnings.0.description'));
    }

    public function test_blade_does_not_crash_when_operation_dashboard_is_null(): void
    {
        $html = View::make('super-admin.dashboard', [
            'summaryCards' => [],
            'liveReadinessCards' => [],
            'warnings' => [],
            'recentTenants' => [],
            'packageBreakdown' => [],
            'onboardingIssues' => [],
            'operationalNotes' => [],
            'systemReadinessChecklist' => [],
            'demoDataChecklist' => [],
            'operationDashboard' => null,
        ])->render();

        $this->assertStringContainsString('Super Admin Operasyon Merkezi', $html);
        $this->assertStringContainsString('Veri hazırlanıyor', $html);
    }

    public function test_blade_renders_fallback_messages_and_passive_action_item_without_link(): void
    {
        $html = View::make('super-admin.dashboard', [
            'summaryCards' => [],
            'liveReadinessCards' => [],
            'warnings' => [],
            'recentTenants' => [],
            'packageBreakdown' => [],
            'onboardingIssues' => [],
            'operationalNotes' => [],
            'systemReadinessChecklist' => [],
            'demoDataChecklist' => [],
            'operationDashboard' => [
                'kpis' => ['cards' => []],
                'action_queue' => [
                    'critical' => [[
                        'title' => 'Pasif Aksiyon',
                        'description' => 'Link yoksa pasif bilgi olarak gösterilmelidir.',
                        'count' => 1,
                        'severity' => 'warning',
                        'severity_label' => 'Kontrol Gerekir',
                        'route' => null,
                        'action_label' => 'İncele',
                        'is_actionable' => false,
                    ]],
                    'today' => [],
                    'technical' => [],
                    'message' => 'Bu bölüm için veri hazırlanamadı.',
                ],
                'tenant_readiness' => ['counts' => [], 'rows' => [], 'message' => 'Bu bölüm için veri hazırlanamadı.'],
                'signup_funnel' => ['counts' => [], 'rows' => [], 'message' => 'Bu bölüm için veri hazırlanamadı.'],
                'upgrade_requests' => ['counts' => [], 'rows' => [], 'message' => 'Bu bölüm için veri hazırlanamadı.'],
                'product_data_hub' => ['counts' => [], 'rows' => [], 'warnings' => ['Bu bölüm için veri hazırlanamadı.'], 'message' => 'Bu bölüm için veri hazırlanamadı.'],
                'system_health' => ['queue_worker' => ['label' => 'Kuyruk Çalışanı', 'status' => 'unknown', 'status_label' => 'Bilinmiyor', 'description' => 'Bu bölüm için veri hazırlanamadı.', 'checked_at' => null, 'route' => null, 'is_placeholder' => true, 'details' => []]],
                'recent_operations' => [],
                'security_warnings' => [],
            ],
        ])->render();

        $this->assertStringContainsString('Bu bölüm için veri hazırlanamadı.', $html);
        $this->assertStringContainsString('Pasif Aksiyon', $html);
        $this->assertStringNotContainsString('href=""', $html);
        $this->assertStringNotContainsString('smtp_password', $html);
    }

    public function test_admin_dashboard_shows_super_admin_transition_link_only_for_platform_admin(): void
    {
        $tenantDashboardResponse = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.dashboard'));

        $tenantDashboardResponse->assertOk();
        $tenantDashboardResponse->assertSee('Super Admin Operasyon Paneline Geç');
        $tenantDashboardResponse->assertSee(route('admin.super.dashboard'), false);

        $normalTenantUser = User::query()->create([
            'name' => 'Normal Tenant User',
            'email' => 'normal-tenant-user@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $normalTenantUser->id,
            'tenant_account_id' => $this->tenant->id,
            'role_id' => $this->tenantAdminRole->id,
        ]);

        $normalResponse = $this->actingAs($normalTenantUser, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.dashboard'));

        $normalResponse->assertOk();
        $normalResponse->assertDontSee('Super Admin Operasyon Paneline Geç');
        $normalResponse->assertDontSee(route('admin.super.dashboard'), false);
    }

    protected function partialOperationDashboardService(): SuperAdminOperationDashboardService
    {
        return Mockery::mock(SuperAdminOperationDashboardService::class, [
            app(\App\Services\SuperAdminDashboardSummaryService::class),
            app(\App\Services\TenantOnboardingStatusService::class),
            app(\App\Services\TenantSubscriptionStatusService::class),
            app(\App\Services\TenantUsageService::class),
            app(\App\Services\TenantAccessService::class),
            app(\App\Services\SuperAdmin\TenantSignupRequestReadinessService::class),
            app(\App\Services\SuperAdmin\TenantUpgradeRequestReviewService::class),
            app(\App\Services\ProductDataHub\ProductHubOperationFlowService::class),
            app(\App\Services\SuperAdmin\ProductDataHubLiveReadinessService::class),
            app(\App\Services\SuperAdminOperationAuditService::class),
            app(\App\Services\SuperAdmin\SuperAdminSystemHealthService::class),
            app(\App\Services\SuperAdmin\ProductionEnvironmentReadinessService::class),
            app(\App\Services\SuperAdmin\NotificationReadinessService::class),
        ])->makePartial();
    }
}
