<?php

namespace App\Services\SuperAdmin;

use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Services\Notifications\NotificationTemplateService;
use App\Services\Notifications\TenantNotificationSettingsService;
use App\Services\Notifications\TenantWhatsappLinkService;
use App\Services\System\SystemHeartbeatService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class NotificationReadinessService
{
    private ?array $cachedReadinessContext = null;

    public function __construct(
        private readonly TenantNotificationSettingsService $settingsService,
        private readonly NotificationTemplateService $templateService,
        private readonly TenantWhatsappLinkService $whatsappLinkService,
        private readonly SystemHeartbeatService $heartbeatService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildReadinessContext(): array
    {
        if (is_array($this->cachedReadinessContext)) {
            return $this->cachedReadinessContext;
        }

        return $this->cachedReadinessContext = [
            'mail_environment' => $this->buildMailEnvironment(),
            'tenant_smtp_summary' => $this->buildTenantSmtpSummary(),
            'notification_templates' => $this->buildNotificationTemplatesSummary(),
            'notification_logs' => $this->buildNotificationLogsSummary(),
            'whatsapp_links' => $this->buildWhatsappLinksSummary(),
            'queue_dependency' => $this->buildQueueDependencySummary(),
            'warnings' => $this->buildWarnings(),
            'checklist' => $this->buildChecklist(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildMailEnvironment(): array
    {
        $checkedAt = $this->checkedAt();
        $mailer = (string) Config::get('mail.default', 'log');
        $smtp = (array) Config::get('mail.mailers.smtp', []);
        $from = (array) Config::get('mail.from', []);
        $appEnv = (string) Config::get('app.env', 'local');

        $host = $this->maskHost((string) ($smtp['host'] ?? ''));
        $username = $this->maskEmail((string) ($smtp['username'] ?? ''));
        $port = $smtp['port'] ?? null;
        $encryption = $smtp['encryption'] ?? null;
        $fromAddress = $this->maskEmail((string) ($from['address'] ?? ''));
        $fromName = filled($from['name'] ?? null) ? Str::limit((string) $from['name'], 60) : 'Tanımlı değil';

        $status = 'healthy';
        $description = 'Mail ortam ayarları canlı kullanıma uygun görünüyor.';
        $details = [
            'Mailer: ' . ($mailer !== '' ? $mailer : 'Tanımlı değil'),
            'Host: ' . ($host !== '' ? $host : 'Tanımlı değil'),
            'Port: ' . ($port ?: 'Tanımlı değil'),
            'Şifreleme: ' . ($encryption ?: 'Tanımlı değil'),
            'Gönderen adresi: ' . $fromAddress,
            'Gönderen adı: ' . $fromName,
            'Kullanıcı adı: ' . $username,
        ];

        if ($appEnv !== 'production') {
            $status = 'warning';
            $description = 'Canlı doğrulama için production env gerekir.';
        }

        if ($mailer === 'log') {
            $status = $appEnv === 'production' ? 'critical' : 'warning';
            $description = 'MAIL_MAILER log modunda; canlıda gerçek SMTP kullanılmalıdır.';
        } elseif ($mailer === 'smtp') {
            if (!filled($smtp['host'] ?? null) || !filled($port) || !filled($from['address'] ?? null) || !filled($from['name'] ?? null)) {
                $status = 'warning';
                $description = 'SMTP etkin görünüyor ancak temel mail alanlarından en az biri eksik.';
            }
        } else {
            $status = 'warning';
            $description = 'MAIL_MAILER smtp dışında bir sürücüye ayarlı; canlı akış ayrıca doğrulanmalı.';
        }

        return $this->section($status, $description, $details, $checkedAt);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildTenantSmtpSummary(): array
    {
        $checkedAt = $this->checkedAt();
        $tenants = TenantAccount::query()->orderBy('name')->get();
        $rows = [];
        $configured = 0;
        $missing = 0;
        $tested = 0;
        $encrypted = 0;
        $missingFieldCounts = [
            'host' => 0,
            'from_email' => 0,
            'username' => 0,
            'password' => 0,
        ];

        foreach ($tenants as $tenant) {
            $smtp = $this->settingsService->getSmtpConfig($tenant);
            $masked = $this->settingsService->maskSmtpSettingsForDisplay($tenant);
            $summary = $this->settingsService->readinessSummary($tenant);
            $passwordSetting = TenantSetting::query()
                ->where('tenant_account_id', $tenant->id)
                ->where('key', 'smtp_password')
                ->value('value');
            $lastTestLog = NotificationLog::query()
                ->forTenant($tenant->id)
                ->where('notification_key', 'smtp_test_mail')
                ->latest()
                ->first();

            $missingFields = [];
            if (!filled($smtp['host'] ?? null)) {
                $missingFields[] = 'Host';
                $missingFieldCounts['host']++;
            }
            if (!filled($smtp['from_email'] ?? null)) {
                $missingFields[] = 'Gönderen';
                $missingFieldCounts['from_email']++;
            }
            if (!filled($smtp['username'] ?? null)) {
                $missingFields[] = 'Kullanıcı adı';
                $missingFieldCounts['username']++;
            }
            if (!filled($smtp['password'] ?? null)) {
                $missingFields[] = 'Şifre';
                $missingFieldCounts['password']++;
            }

            $isConfigured = (bool) ($smtp['is_active'] ?? false)
                && filled($smtp['host'] ?? null)
                && filled($smtp['from_email'] ?? null);
            $hasTestLog = $lastTestLog !== null;
            $isEncrypted = is_string($passwordSetting)
                && str_starts_with($passwordSetting, TenantNotificationSettingsService::ENCRYPTED_PREFIX);

            if ($isConfigured) {
                $configured++;
            } else {
                $missing++;
            }

            if ($hasTestLog) {
                $tested++;
            }

            if ($isEncrypted) {
                $encrypted++;
            }

            $rows[] = [
                'tenant' => $tenant->name,
                'smtp_status' => data_get($summary, 'smtp.status_label', $isConfigured ? 'Hazır' : 'Kontrol Gerekir'),
                'missing_fields' => $missingFields,
                'last_test' => $lastTestLog?->created_at?->format('d.m.Y H:i') ?: 'Henüz test yok',
                'last_updated' => $this->formatDate($tenant->settings()->whereIn('key', TenantNotificationSettingsService::SMTP_KEYS)->latest('updated_at')->value('updated_at')),
                'action_route' => route('admin.super.tenants.show', $tenant),
                'username_masked' => $masked['smtp_username'] ?: 'Tanımlı değil',
                'password_configured' => (bool) ($masked['smtp_password_configured'] ?? false),
            ];
        }

        $status = $missing > 0 ? 'warning' : 'healthy';
        if ($tenants->isEmpty()) {
            $status = 'unknown';
        }

        $description = $tenants->isEmpty()
            ? 'Abone Firma SMTP özeti için kayıt bulunamadı.'
            : 'SMTP ayarı olan ve eksik kalan Abone Firmalar özetlendi.';

        return array_merge(
            $this->section(
                $status,
                $description,
                [
                    'Toplam Abone Firma: ' . $tenants->count(),
                    'SMTP hazır: ' . $configured,
                    'SMTP eksik: ' . $missing,
                    'Test logu görülen: ' . $tested,
                    'Şifreli saklandığı görülen: ' . $encrypted,
                ],
                $checkedAt
            ),
            [
                'counts' => [
                    'configured' => $configured,
                    'missing' => $missing,
                    'tested' => $tested,
                    'encrypted' => $encrypted,
                ],
                'missing_field_counts' => $missingFieldCounts,
                'rows' => array_slice($rows, 0, 8),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildNotificationTemplatesSummary(): array
    {
        $checkedAt = $this->checkedAt();
        $templates = NotificationTemplate::query()->get();
        $configTemplates = collect((array) config('prodelya_notifications.events', []));
        $customerTemplates = $templates->filter(fn (NotificationTemplate $template): bool => $template->audience_type === NotificationTemplate::AUDIENCE_CUSTOMER);
        $inactiveCount = $templates->where('is_active', false)->count();
        $blockedVariables = $customerTemplates->sum(function (NotificationTemplate $template): int {
            return count($this->templateService->validateTemplateVariables($template)['blocked_variables'] ?? []);
        });

        $status = 'healthy';
        $description = 'Bildirim şablonları güvenli değişken kurallarıyla okunuyor.';

        if ($configTemplates->isEmpty() && $templates->isEmpty()) {
            $status = 'unknown';
            $description = 'Bildirim şablon kataloğu görünmüyor.';
        } elseif ($inactiveCount > 0 || $blockedVariables > 0) {
            $status = 'warning';
            $description = 'Pasif veya gözden geçirilmesi gereken bildirim şablonları var.';
        }

        return array_merge(
            $this->section(
                $status,
                $description,
                [
                    'Katalog şablonu: ' . $configTemplates->count(),
                    'Veritabanı şablonu: ' . $templates->count(),
                    'Aktif şablon: ' . $templates->where('is_active', true)->count(),
                    'Pasif şablon: ' . $inactiveCount,
                    'Müşteri-facing şablon: ' . $customerTemplates->count(),
                    'Bloklanan değişken izi: ' . $blockedVariables,
                ],
                $checkedAt
            ),
            [
                'counts' => [
                    'total' => $templates->count(),
                    'catalog_total' => $configTemplates->count(),
                    'active' => $templates->where('is_active', true)->count(),
                    'inactive' => $inactiveCount,
                    'customer_facing' => $customerTemplates->count(),
                    'blocked_variable_hits' => $blockedVariables,
                ],
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildNotificationLogsSummary(): array
    {
        $checkedAt = $this->checkedAt();
        $hasTable = Schema::hasTable('notification_logs');

        if (!$hasTable) {
            return array_merge(
                $this->section('unknown', 'Bildirim log tablosu görünmüyor.', ['notification_logs tablosu bu ortamda okunamadı.'], $checkedAt),
                ['counts' => []]
            );
        }

        $query = NotificationLog::query();
        $last24Hours = (clone $query)->where('created_at', '>=', now()->subDay())->count();
        $failedCount = (clone $query)->where('status', NotificationLog::STATUS_FAILED)->count();
        $lastFailed = (clone $query)->where('status', NotificationLog::STATUS_FAILED)->latest('created_at')->first();
        $lastSent = (clone $query)->whereIn('status', [NotificationLog::STATUS_SENT, NotificationLog::STATUS_LINK_CREATED])->latest('created_at')->first();
        $statusBreakdown = [
            'pending' => (clone $query)->where('status', NotificationLog::STATUS_PENDING)->count(),
            'sent' => (clone $query)->where('status', NotificationLog::STATUS_SENT)->count(),
            'failed' => $failedCount,
            'preview' => (clone $query)->where('status', NotificationLog::STATUS_PREVIEW)->count(),
            'skipped' => (clone $query)->where('status', NotificationLog::STATUS_SKIPPED)->count(),
            'link_created' => (clone $query)->where('status', NotificationLog::STATUS_LINK_CREATED)->count(),
        ];

        $status = $failedCount > 0 ? 'warning' : 'healthy';
        $description = $failedCount > 0
            ? 'Bildirim loglarında başarısız kayıtlar var; retry öncesi neden incelenmelidir.'
            : 'Bildirim loglarında kritik başarısızlık görünmüyor.';

        return array_merge(
            $this->section(
                $status,
                $description,
                [
                    'Son 24 saat log sayısı: ' . $last24Hours,
                    'Başarısız kayıt: ' . $failedCount,
                    'Son başarısız: ' . ($lastFailed?->created_at?->format('d.m.Y H:i') ?: 'Yok'),
                    'Son başarılı: ' . ($lastSent?->created_at?->format('d.m.Y H:i') ?: 'Yok'),
                    'Ham payload dashboard üzerinde gösterilmez.',
                ],
                $checkedAt
            ),
            [
                'counts' => $statusBreakdown,
                'last_failed_at' => $lastFailed?->created_at?->format('d.m.Y H:i'),
                'last_sent_at' => $lastSent?->created_at?->format('d.m.Y H:i'),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildWhatsappLinksSummary(): array
    {
        $checkedAt = $this->checkedAt();
        $activeTenants = TenantSetting::query()
            ->where('key', 'whatsapp_is_active')
            ->where('value', '1')
            ->distinct('tenant_account_id')
            ->count('tenant_account_id');
        $testPhoneTenants = TenantSetting::query()
            ->where('key', 'whatsapp_test_phone')
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->distinct('tenant_account_id')
            ->count('tenant_account_id');
        $lastLinkLog = Schema::hasTable('notification_logs')
            ? NotificationLog::query()
                ->where('channel', NotificationLog::CHANNEL_WHATSAPP_LINK)
                ->latest('created_at')
                ->first()
            : null;

        $preview = $this->whatsappLinkService->buildPreview(
            new TenantAccount(['name' => 'Prodelya']),
            [
                'customer_name' => 'Deneme Musteri',
                'recipient_phone' => '05550000000',
                'message' => 'token api_key group_code supplier_cost profit file_path pdh_raw KDV bakiye maliyet',
                'message_type' => TenantWhatsappLinkService::TYPE_GENERAL,
                'public_link' => 'https://example.test/public/preview',
            ]
        );

        $message = (string) ($preview['message'] ?? '');
        $status = 'healthy';
        $description = 'WhatsApp akışı link tabanlı ve güvenli metin temizliği aktif.';

        if ($activeTenants === 0) {
            $status = 'warning';
            $description = 'WhatsApp akışı link tabanlı çalışır; aktif tenant görünmüyor veya ayarlar kontrol gerektiriyor.';
        }

        return array_merge(
            $this->section(
                $status,
                $description,
                [
                    'Akış türü: link tabanlı',
                    'Aktif tenant sayısı: ' . $activeTenants,
                    'Test telefonu tanımlı tenant: ' . $testPhoneTenants,
                    'Son WhatsApp link logu: ' . ($lastLinkLog?->created_at?->format('d.m.Y H:i') ?: 'Yok'),
                    'Gerçek WhatsApp API entegrasyonu bu fazda yok.',
                ],
                $checkedAt
            ),
            [
                'counts' => [
                    'active_tenants' => $activeTenants,
                    'test_phone_tenants' => $testPhoneTenants,
                ],
                'sanitized_preview' => Str::limit($message, 160),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildQueueDependencySummary(): array
    {
        $checkedAt = $this->checkedAt();
        $queueConnection = (string) Config::get('queue.default', 'sync');
        $worker = $this->heartbeatService->statusFor('queue_worker', 15, 45);
        $failedJobsCount = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : null;

        $status = 'healthy';
        $description = 'Bildirim akışı için kuyruk bağlantısı ve worker sinyali okunuyor.';

        if ($queueConnection === 'sync') {
            $status = 'warning';
            $description = 'Queue bağlantısı sync modunda; canlı yüksek trafik için worker stratejisi ayrıca doğrulanmalı.';
        } elseif (($worker['status'] ?? 'unknown') !== 'healthy') {
            $status = $worker['status'] === 'critical' ? 'critical' : 'warning';
            $description = 'Queue worker heartbeat sinyali güncel değil veya doğrulanamadı.';
        }

        if (($failedJobsCount ?? 0) > 0 && $status === 'healthy') {
            $status = 'warning';
            $description = 'Queue worker sinyali mevcut ancak başarısız işler ayrıca kontrol gerektiriyor.';
        }

        return array_merge(
            $this->section(
                $status,
                $description,
                [
                    'Queue bağlantısı: ' . $queueConnection,
                    'Worker heartbeat: ' . ($worker['status_label'] ?? 'Bilinmiyor'),
                    'Son worker sinyali: ' . ($worker['last_seen_at'] ?? 'Yok'),
                    $failedJobsCount !== null ? 'Başarısız iş sayısı: ' . $failedJobsCount : 'Başarısız işler tablosu görünmüyor.',
                    'Mail gönderimleri queue kullanıyorsa worker kesintisi gecikmeye neden olabilir.',
                ],
                $checkedAt
            ),
            [
                'queue_connection' => $queueConnection,
                'worker_status' => $worker['status'] ?? 'unknown',
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function actionableWarnings(): array
    {
        $context = $this->buildReadinessContext();
        $warnings = [];

        if (in_array(data_get($context, 'mail_environment.status'), ['warning', 'critical'], true)) {
            $warnings[] = [
                'key' => 'notification_mail_environment',
                'title' => 'SMTP / Bildirim Hazırlığı',
                'tone' => data_get($context, 'mail_environment.status') === 'critical' ? 'warning' : 'info',
                'description' => data_get($context, 'mail_environment.description', 'SMTP ortamı kontrol edilmelidir.'),
            ];
        }

        if (((int) data_get($context, 'tenant_smtp_summary.counts.missing', 0)) > 0) {
            $warnings[] = [
                'key' => 'notification_tenant_smtp_missing',
                'title' => 'Tenant SMTP eksikleri izlenmeli',
                'tone' => 'warning',
                'description' => data_get($context, 'tenant_smtp_summary.counts.missing') . ' Abone Firmada SMTP ayarı eksik veya tamamlanmamış görünüyor.',
            ];
        }

        if (((int) data_get($context, 'notification_logs.counts.failed', 0)) > 0) {
            $warnings[] = [
                'key' => 'notification_failed_logs',
                'title' => 'Bildirim loglarında başarısız kayıtlar var',
                'tone' => 'warning',
                'description' => data_get($context, 'notification_logs.description', 'Başarısız bildirim kayıtları gözden geçirilmelidir.'),
            ];
        }

        $warnings[] = [
            'key' => 'notification_whatsapp_link_safety',
            'title' => 'WhatsApp link metinlerinde güvenli alan temizliği korunmalı',
            'tone' => 'info',
            'description' => 'Müşteri-facing WhatsApp metinlerinde maliyet ve teknik alan sızıntısı engellenmeye devam etmelidir.',
        ];

        return $warnings;
    }

    /**
     * @return array<int, string>
     */
    protected function buildWarnings(): array
    {
        $warnings = [];
        $context = [
            $this->buildMailEnvironment(),
            $this->buildTenantSmtpSummary(),
            $this->buildNotificationTemplatesSummary(),
            $this->buildNotificationLogsSummary(),
            $this->buildWhatsappLinksSummary(),
            $this->buildQueueDependencySummary(),
        ];

        foreach ($context as $section) {
            if (in_array($section['status'] ?? 'unknown', ['warning', 'critical'], true)) {
                $warnings[] = $section['description'] ?? 'Kontrol gerektiren bildirim sinyali var.';
            }
        }

        return array_values(array_unique(array_map(fn (string $warning): string => Str::limit(trim(strip_tags($warning)), 180), $warnings)));
    }

    /**
     * @return array<int, string>
     */
    protected function buildChecklist(): array
    {
        return [
            'MAIL_MAILER canlıda smtp olmalı.',
            'Tenant SMTP alanları tamamlanmalı ve şifre maskeli/şifreli saklanmalı.',
            'Bildirim loglarında başarısız kayıtlar düzenli izlenmeli.',
            'WhatsApp link akışı müşteri-facing güvenli metin temizliğiyle kullanılmalı.',
            'Queue worker kesintisi varsa bildirim gecikmesi operasyon notuna işlenmeli.',
        ];
    }

    /**
     * @param array<int, string> $details
     * @return array<string, mixed>
     */
    protected function section(string $status, string $description, array $details, string $checkedAt): array
    {
        return [
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'description' => Str::limit(trim(strip_tags($description)), 180),
            'details' => array_values(array_map(
                fn (string $detail): string => Str::limit(trim(strip_tags($detail)), 180),
                array_filter($details)
            )),
            'checked_at' => $checkedAt,
        ];
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'healthy' => 'Sağlıklı',
            'warning' => 'Kontrol Gerekir',
            'critical' => 'Kritik',
            default => 'Bilinmiyor',
        };
    }

    protected function checkedAt(): string
    {
        return now()->format('d.m.Y H:i');
    }

    protected function formatDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d.m.Y H:i');
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return now()->parse($value)->format('d.m.Y H:i');
            } catch (\Throwable) {
                return '-';
            }
        }

        return '-';
    }

    protected function maskHost(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 'Tanımlı değil';
        }

        $parts = explode('.', $trimmed);
        if (count($parts) < 2) {
            return Str::limit($trimmed, 20);
        }

        $first = array_shift($parts);
        $last = implode('.', $parts);

        return Str::substr((string) $first, 0, 2) . '***.' . $last;
    }

    protected function maskEmail(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 'Tanımlı değil';
        }

        if (!filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            return Str::limit($trimmed, 3, '***');
        }

        [$local, $domain] = array_pad(explode('@', $trimmed, 2), 2, '');

        return Str::substr($local, 0, 2) . '***@' . Str::substr($domain, 0, 2) . '***';
    }
}
