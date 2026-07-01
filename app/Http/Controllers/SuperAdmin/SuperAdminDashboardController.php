<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\SuperAdmin\SuperAdminOperationDashboardService;
use App\Services\SuperAdminDashboardSummaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SuperAdminDashboardController extends Controller
{
    public function __construct(
        private readonly SuperAdminDashboardSummaryService $summaryService,
        private readonly SuperAdminOperationDashboardService $operationDashboardService,
    ) {
    }

    /**
     * Show the super admin dashboard.
     */
    public function index(Request $request)
    {
        if ($this->shouldWriteLocalDebugLog()) {
            Log::info('super_admin_dashboard.request_started', [
                'user_id' => $request->user()?->id,
                'host' => $request->getHost(),
                'environment' => app()->environment(),
                'path' => $request->path(),
            ]);
        }

        if ($this->shouldReturnMinimalResponse($request)) {
            $this->localDebugLog('minimal_render', 'Local minimal render yaniti donuldu.');

            return response('Super Admin Dashboard controller çalışıyor.', 200)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        try {
            $stats = $this->measureStep('summary_service', fn (): array => $this->summaryService->build());
        } catch (\Throwable $exception) {
            $this->localDebugLog('summary_service_failed', $exception->getMessage());
            $stats = [
                'summaryCards' => [],
                'liveReadinessCards' => [],
                'warnings' => [],
                'recentTenants' => [],
                'packageBreakdown' => [],
                'onboardingIssues' => [],
                'operationalNotes' => [],
                'systemReadinessChecklist' => [],
                'demoDataChecklist' => [],
            ];
        }

        try {
            $operationDashboard = $this->measureStep('operation_dashboard_service', fn (): array => $this->operationDashboardService->buildDashboardContext());
        } catch (\Throwable $exception) {
            $this->localDebugLog('operation_dashboard_service_failed', $exception->getMessage());
            $operationDashboard = [
                'kpis' => ['cards' => []],
                'action_queue' => ['critical' => [], 'today' => [], 'technical' => []],
                'tenant_readiness' => ['counts' => [], 'rows' => []],
                'signup_funnel' => ['counts' => [], 'rows' => []],
                'upgrade_requests' => ['counts' => [], 'rows' => []],
                'product_data_hub' => [
                    'counts' => [],
                    'rows' => [],
                    'warnings' => ['Operasyon paneli Product Data Hub özeti hazırlanamadı.'],
                ],
                'system_health' => [
                    'queue_worker' => [
                        'label' => 'Kuyruk Çalışanı',
                        'status' => 'unknown',
                        'status_label' => 'Bilinmiyor',
                        'description' => 'Operasyon paneli sağlık verisi hazırlanamadı.',
                        'route' => null,
                        'is_placeholder' => true,
                    ],
                ],
                'recent_operations' => [],
                'security_warnings' => [[
                    'key' => 'dashboard_context_failed',
                    'title' => 'Operasyon paneli verisi hazırlanamadı',
                    'tone' => 'warning',
                    'description' => 'Operasyon paneli verisi hazırlanamadı; güvenli boş veri ile devam edildi.',
                ]],
            ];
        }

        $this->localDebugLog('view_render_started', 'Super Admin dashboard view render basliyor.');

        return view('super-admin.dashboard', [
            ...$stats,
            'operationDashboard' => $operationDashboard,
        ]);
    }

    protected function measureStep(string $key, callable $callback): mixed
    {
        $startedAt = microtime(true);

        try {
            return $callback();
        } finally {
            if ($this->shouldWriteLocalDebugLog()) {
                Log::info('super_admin_dashboard.step_timing', [
                    'step' => $key,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);
            }
        }
    }

    protected function shouldReturnMinimalResponse(Request $request): bool
    {
        return app()->environment(['local', 'testing']) && $request->boolean('minimal');
    }

    protected function shouldWriteLocalDebugLog(): bool
    {
        return app()->environment('local');
    }

    protected function localDebugLog(string $event, string $message): void
    {
        if (! $this->shouldWriteLocalDebugLog()) {
            return;
        }

        Log::info('super_admin_dashboard.' . $event, [
            'message' => str($message)->limit(220)->toString(),
        ]);
    }
}
