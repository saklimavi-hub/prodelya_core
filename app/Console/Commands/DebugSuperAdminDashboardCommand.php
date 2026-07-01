<?php

namespace App\Console\Commands;

use App\Services\SuperAdmin\SuperAdminOperationDashboardService;
use App\Services\SuperAdminDashboardSummaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\View;
use Throwable;

class DebugSuperAdminDashboardCommand extends Command
{
    protected $signature = 'prodelya:debug-super-admin-dashboard {--section=all}';

    protected $description = 'Super Admin dashboard context ve render durumunu güvenli şekilde teşhis eder.';

    public function __construct(
        private readonly SuperAdminDashboardSummaryService $summaryService,
        private readonly SuperAdminOperationDashboardService $operationDashboardService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $section = strtolower((string) $this->option('section'));

        $this->components->info('Super Admin dashboard debug başlıyor.');

        $this->table(
            ['Anahtar', 'Değer'],
            [
                ['APP_ENV', (string) config('app.env')],
                ['DB_CONNECTION', (string) config('database.default')],
                ['SESSION_DRIVER', (string) config('session.driver')],
                ['CACHE_STORE', (string) config('cache.default')],
                ['SESSION_DOMAIN', (string) (config('session.domain') ?? 'null')],
                ['SESSION_COOKIE', (string) config('session.cookie')],
                ['SQLite Local Koruma', ((bool) config('prodelya_local.sqlite_lock_protection.active')) ? 'aktif' : 'pasif'],
            ]
        );

        $summary = [];
        $dashboard = [];
        $rows = null;

        if (in_array($section, ['all', 'summary'], true)) {
            try {
                $summary = $this->summaryService->build();
                $this->components->info('Summary service çalıştı.');
                $this->line('summaryCards=' . count($summary['summaryCards'] ?? []));
                $this->line('liveReadinessCards=' . count($summary['liveReadinessCards'] ?? []));
                $this->line('warnings=' . count($summary['warnings'] ?? []));
                $this->line('recentTenants=' . count($summary['recentTenants'] ?? []));
            } catch (Throwable $exception) {
                $this->components->error('Summary service hata verdi: ' . $this->safeException($exception));

                return self::FAILURE;
            }
        }

        if (in_array($section, ['all', 'context'], true)) {
            try {
                $dashboard = $this->operationDashboardService->buildDashboardContext();
                $this->components->info('Operation dashboard service çalıştı.');
                $this->line('kpis=' . count(data_get($dashboard, 'kpis.cards', [])));
                $this->line('action_queue.critical=' . count(data_get($dashboard, 'action_queue.critical', [])));
                $this->line('action_queue.today=' . count(data_get($dashboard, 'action_queue.today', [])));
                $this->line('action_queue.technical=' . count(data_get($dashboard, 'action_queue.technical', [])));
                $this->line('tenant_readiness.rows=' . count(data_get($dashboard, 'tenant_readiness.rows', [])));
                $this->line('signup_funnel.rows=' . count(data_get($dashboard, 'signup_funnel.rows', [])));
                $this->line('upgrade_requests.rows=' . count(data_get($dashboard, 'upgrade_requests.rows', [])));
                $this->line('product_data_hub.rows=' . count(data_get($dashboard, 'product_data_hub.rows', [])));
                $this->line('recent_operations=' . count(data_get($dashboard, 'recent_operations', [])));
                $this->line('security_warnings=' . count(data_get($dashboard, 'security_warnings', [])));
            } catch (Throwable $exception) {
                $this->components->error('Operation dashboard service hata verdi: ' . $this->safeException($exception));

                return self::FAILURE;
            }
        } elseif (! in_array($section, ['summary', 'render'], true)) {
            try {
                $rows = $this->summaryService->tenantRows();
                $result = $this->invokeOperationSection($section, $rows);
                $this->components->info('Section çalıştı: ' . $section);
                $this->renderSectionSummary($section, $result);
            } catch (Throwable $exception) {
                $this->components->error('Section hata verdi [' . $section . ']: ' . $this->safeException($exception));

                return self::FAILURE;
            }
        }

        if (in_array($section, ['all', 'render'], true)) {
            try {
                if ($summary === []) {
                    $summary = $this->summaryService->build();
                }

                if ($dashboard === []) {
                    $dashboard = $this->operationDashboardService->buildDashboardContext();
                }

                $html = View::make('super-admin.dashboard', [
                    ...$summary,
                    'operationDashboard' => $dashboard,
                ])->render();

                $checks = [
                    'Super Admin Operasyon Merkezi' => str_contains($html, 'Super Admin Operasyon Merkezi'),
                    'Operasyon Özeti' => str_contains($html, 'Operasyon Özeti'),
                    'Aksiyon Gerektirenler' => str_contains($html, 'Aksiyon Gerektirenler'),
                    'Sistem Sağlığı' => str_contains($html, 'Sistem Sağlığı'),
                ];

                $this->components->info('Blade render başarılı.');
                foreach ($checks as $label => $result) {
                    $this->line($label . '=' . ($result ? 'evet' : 'hayır'));
                }
            } catch (Throwable $exception) {
                $this->components->error('Dashboard Blade render hata verdi: ' . $this->safeException($exception));

                return self::FAILURE;
            }
        }

        $this->components->info('Super Admin dashboard debug tamamlandı.');

        return self::SUCCESS;
    }

    protected function safeException(Throwable $exception): string
    {
        return class_basename($exception) . ': ' . str($exception->getMessage())->limit(220)->toString();
    }

    protected function invokeOperationSection(string $section, mixed $rows): mixed
    {
        return match ($section) {
            'kpis' => $this->invokeProtected('buildKpisSection', [$rows, $this->invokeProtected('buildSignupFunnelSection'), $this->invokeProtected('buildUpgradeRequestsSection')]),
            'action_queue' => $this->invokeProtected('buildActionQueueSection', [
                $rows,
                $this->invokeProtected('buildSignupFunnelSection'),
                $this->invokeProtected('buildUpgradeRequestsSection'),
                $this->invokeProtected('buildProductDataHubSection'),
                $this->invokeProtected('buildSystemHealthSection'),
            ]),
            'tenant_readiness' => $this->invokeProtected('buildTenantReadinessSection', [$rows]),
            'signup_funnel' => $this->invokeProtected('buildSignupFunnelSection'),
            'upgrade_requests' => $this->invokeProtected('buildUpgradeRequestsSection'),
            'product_data_hub' => $this->invokeProtected('buildProductDataHubSection'),
            'system_health' => $this->invokeProtected('buildSystemHealthSection'),
            'recent_operations' => $this->invokeProtected('buildRecentOperationsSection'),
            'security_warnings' => $this->invokeProtected('buildSecurityWarningsSection'),
            default => throw new \InvalidArgumentException('Bilinmeyen section: ' . $section),
        };
    }

    protected function renderSectionSummary(string $section, mixed $result): void
    {
        if (is_array($result)) {
            $this->line('array_keys=' . implode(', ', array_keys($result)));

            if (isset($result['rows']) && is_array($result['rows'])) {
                $this->line('rows=' . count($result['rows']));
            }

            if (isset($result['cards']) && is_array($result['cards'])) {
                $this->line('cards=' . count($result['cards']));
            }

            if (in_array($section, ['action_queue', 'system_health'], true)) {
                $json = json_encode($result, JSON_UNESCAPED_UNICODE);
                $this->line('json_length=' . strlen((string) $json));
            }

            return;
        }

        $this->line('result_type=' . gettype($result));
    }

    protected function invokeProtected(string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($this->operationDashboardService, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->operationDashboardService, $arguments);
    }
}
