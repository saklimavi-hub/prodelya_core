<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationEventCatalogService;
use App\Services\Notifications\TenantNotificationSettingsService;
use App\Services\TenantAccessService;
use App\Services\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\View;

class NotificationCenterController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected TenantNotificationSettingsService $notificationSettingsService,
        protected TenantAccessService $tenantAccessService,
        protected NotificationEventCatalogService $eventCatalogService,
    ) {
    }

    public function index(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        $recentLogs = NotificationLog::query()
            ->forTenant($tenant->id)
            ->latest()
            ->take(8)
            ->get();

        $todayCounts = NotificationLog::query()
            ->forTenant($tenant->id)
            ->whereDate('created_at', today())
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $templateCollection = NotificationTemplate::query()
            ->where(function ($query) use ($tenant) {
                $query->where('tenant_account_id', $tenant->id)
                    ->orWhereNull('tenant_account_id');
            })
            ->get();

        return view('admin.notifications.index', [
            'tenant' => $tenant,
            'recentLogs' => $recentLogs,
            'eventLabels' => $this->eventLabelsFor($recentLogs),
            'todayLogCount' => array_sum($todayCounts),
            'statusSummary' => [
                'failed' => (int) ($todayCounts[NotificationLog::STATUS_FAILED] ?? 0),
                'skipped' => (int) ($todayCounts[NotificationLog::STATUS_SKIPPED] ?? 0),
                'sent' => (int) ($todayCounts[NotificationLog::STATUS_SENT] ?? 0),
                'link_created' => (int) ($todayCounts[NotificationLog::STATUS_LINK_CREATED] ?? 0),
                'preview' => (int) ($todayCounts[NotificationLog::STATUS_PREVIEW] ?? 0),
            ],
            'smtpActive' => $this->notificationSettingsService->isEmailEnabled($tenant),
            'whatsappActive' => $this->notificationSettingsService->isWhatsappEnabled($tenant),
            'templateCount' => $templateCollection->count(),
            'urgentItems' => $this->urgentItems($tenant, [
                'failed' => (int) ($todayCounts[NotificationLog::STATUS_FAILED] ?? 0),
                'smtp_active' => $this->notificationSettingsService->isEmailEnabled($tenant),
                'whatsapp_active' => $this->notificationSettingsService->isWhatsappEnabled($tenant),
                'templates' => $templateCollection,
            ]),
            'quickLinks' => $this->quickLinks($tenant),
        ]);
    }

    private function quickLinks($tenant): array
    {
        return array_values(array_filter([
            $this->quickLink($tenant, 'Son Bildirimler', 'admin.notifications.logs.index', 'notification_logs'),
            $this->quickLink($tenant, 'Başarısızlar', 'admin.notifications.logs.index', 'notification_logs', ['status' => NotificationLog::STATUS_FAILED]),
            $this->quickLink($tenant, 'WhatsApp Linkleri', 'admin.notifications.logs.index', 'notification_logs', ['channel' => NotificationLog::CHANNEL_WHATSAPP_LINK]),
            $this->quickLink($tenant, 'Mail Önizleme / Pending', 'admin.notifications.logs.index', 'notification_logs', ['status' => NotificationLog::STATUS_PREVIEW, 'channel' => NotificationLog::CHANNEL_EMAIL]),
            $this->quickLink($tenant, 'Şablonları Düzenle', 'admin.notifications.templates.index', 'notification_templates'),
            $this->quickLink($tenant, 'SMTP Ayarları', 'admin.settings.notifications.smtp', 'smtp_settings'),
            $this->quickLink($tenant, 'WhatsApp Hazir Mesaj', 'admin.settings.notifications.whatsapp', 'whatsapp_links'),
        ]));
    }

    private function quickLink($tenant, string $label, string $routeName, string $featureKey, array $parameters = []): ?array
    {
        if (!Route::has($routeName) || !$this->tenantAccessService->canAccessFeature($tenant, $featureKey, 'notification_center')) {
            return null;
        }

        return [
            'label' => $label,
            'route' => route($routeName, $parameters),
        ];
    }

    private function eventLabelsFor($logs): array
    {
        $labels = [];

        foreach ($logs as $log) {
            $labels[$log->id] = $this->eventCatalogService->getEvent((string) $log->notification_key)['label']
                ?? ucfirst(str_replace('_', ' ', (string) $log->notification_key));
        }

        return $labels;
    }

    private function urgentItems($tenant, array $state): array
    {
        $items = [];

        if (($state['failed'] ?? 0) > 0 && Route::has('admin.notifications.logs.index')) {
            $items[] = [
                'title' => 'Başarısız bildirimler var',
                'description' => 'Bugün gönderilemeyen bildirimler dikkat bekliyor.',
                'action_label' => 'Başarısızları Gör',
                'action_route' => route('admin.notifications.logs.index', ['status' => NotificationLog::STATUS_FAILED]),
                'tone' => 'red',
            ];
        }

        if (!($state['smtp_active'] ?? false) && $this->tenantAccessService->canAccessFeature($tenant, 'smtp_settings', 'notification_center') && Route::has('admin.settings.notifications.smtp')) {
            $items[] = [
                'title' => 'Mail gönderimi kapalı',
                'description' => 'E-posta bildirimleri için SMTP ayarını tamamlayın veya aktifleştirin.',
                'action_label' => 'SMTP Ayarları',
                'action_route' => route('admin.settings.notifications.smtp'),
                'tone' => 'amber',
            ];
        }

        if (!($state['whatsapp_active'] ?? false) && $this->tenantAccessService->canAccessFeature($tenant, 'whatsapp_links', 'notification_center') && Route::has('admin.settings.notifications.whatsapp')) {
            $items[] = [
                'title' => 'WhatsApp hazır mesaj kapalı',
                'description' => 'Hazır mesaj ve link akışı için WhatsApp ayarını açabilirsiniz.',
                'action_label' => 'WhatsApp Ayarları',
                'action_route' => route('admin.settings.notifications.whatsapp'),
                'tone' => 'amber',
            ];
        }

        if (($state['templates'] instanceof \Illuminate\Support\Collection)
            && $this->tenantAccessService->canAccessFeature($tenant, 'notification_templates', 'notification_center')
            && $state['templates']->where('is_active', true)->isEmpty()
            && Route::has('admin.notifications.templates.index')) {
            $items[] = [
                'title' => 'Aktif şablon görünmüyor',
                'description' => 'Kritik bildirimlerin dili için en az bir aktif şablonu gözden geçirin.',
                'action_label' => 'Şablonları Düzenle',
                'action_route' => route('admin.notifications.templates.index'),
                'tone' => 'gray',
            ];
        }

        return $items;
    }
}
