<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Services\Notifications\NotificationEventCatalogService;
use App\Services\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class NotificationLogController extends Controller
{
    private const HIDDEN_PUBLIC_LINK_LABEL = '[public-onay-linki-gizlendi]';

    public function __construct(
        protected TenantResolver $tenantResolver,
        protected NotificationEventCatalogService $eventCatalogService,
    ) {
    }

    public function index(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $filters = $this->validatedFilters($request);

        $query = NotificationLog::query()
            ->forTenant($tenant->id)
            ->latest();

        if ($filters['event'] !== null) {
            $query->where('notification_key', $this->eventCatalogService->normalizeEventKey($filters['event']));
        }

        if ($filters['channel'] !== null) {
            $query->where('channel', $filters['channel']);
        }

        if ($filters['audience'] !== null) {
            $query->where('audience_type', $filters['audience']);
        }

        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }

        if ($filters['recipient'] !== null) {
            $query->where(function ($inner) use ($filters) {
                $inner->where('recipient_name', 'like', '%' . $filters['recipient'] . '%')
                    ->orWhere('recipient_email', 'like', '%' . $filters['recipient'] . '%')
                    ->orWhere('recipient_phone', 'like', '%' . $filters['recipient'] . '%');
            });
        }

        if ($filters['source_type'] !== null) {
            $query->where('related_type', $filters['source_type']);
        }

        if ($filters['date_from'] !== null) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== null) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $logs = $query->paginate(20)->withQueryString();

        return view('admin.notifications.logs.index', [
            'tenant' => $tenant,
            'logs' => $logs,
            'filters' => $filters,
            'eventOptions' => $this->eventCatalogService->eventOptionsForAdmin(),
            'statusOptions' => NotificationLog::statusLabels(),
            'channelOptions' => NotificationLog::channelLabels(),
            'audienceOptions' => \App\Models\NotificationTemplate::audienceLabels(),
            'eventLabels' => $this->eventLabelsFor($logs->getCollection()),
            'sourceTypeOptions' => NotificationLog::query()
                ->forTenant($tenant->id)
                ->whereNotNull('related_type')
                ->distinct()
                ->orderBy('related_type')
                ->pluck('related_type')
                ->all(),
        ]);
    }

    public function show(Request $request, NotificationLog $notificationLog): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        abort_unless($notificationLog->tenant_account_id === $tenant->id, 404);

        $notificationLog->loadMissing(['creator', 'template', 'related']);

        return view('admin.notifications.logs.show', [
            'tenant' => $tenant,
            'log' => $notificationLog,
            'eventLabel' => $this->eventCatalogService->getEvent((string) $notificationLog->notification_key)['label']
                ?? ucfirst(str_replace('_', ' ', (string) $notificationLog->notification_key)),
            'relatedLabel' => $this->relatedLabel($notificationLog),
            'safeMeta' => $this->sanitizeStructured($notificationLog->meta_json),
            'safeProviderResponse' => $this->sanitizeStructured($notificationLog->provider_response),
        ]);
    }

    private function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'event' => ['nullable', 'string', 'max:120'],
            'channel' => ['nullable', 'string', 'max:40'],
            'audience' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:40'],
            'recipient' => ['nullable', 'string', 'max:190'],
            'source_type' => ['nullable', 'string', 'max:190'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        return [
            'event' => $validated['event'] ?? null,
            'channel' => $validated['channel'] ?? null,
            'audience' => $validated['audience'] ?? null,
            'status' => $validated['status'] ?? null,
            'recipient' => $validated['recipient'] ?? null,
            'source_type' => $validated['source_type'] ?? null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
        ];
    }

    private function eventLabelsFor(Collection $logs): array
    {
        $labels = [];

        foreach ($logs as $log) {
            $labels[$log->id] = $this->eventCatalogService->getEvent((string) $log->notification_key)['label']
                ?? ucfirst(str_replace('_', ' ', (string) $log->notification_key));
        }

        return $labels;
    }

    private function relatedLabel(NotificationLog $log): string
    {
        if ($log->related) {
            foreach (['document_number', 'work_form_number', 'request_number', 'payment_reference', 'tracking_number'] as $field) {
                if (filled(data_get($log->related, $field))) {
                    return class_basename($log->related_type) . ' / ' . data_get($log->related, $field);
                }
            }
        }

        if ($log->related_type && $log->related_id) {
            return class_basename((string) $log->related_type) . ' #' . $log->related_id;
        }

        return 'Bagli kaynak yok';
    }

    private function sanitizeStructured(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                $normalized = Str::lower((string) $key);

                if (preg_match('/smtp_password|mail_password|api_key|token|file_path|physical_path|raw_xml|raw_json|pdh_raw|group_code|profit|cost/u', $normalized)) {
                    continue;
                }

                $sanitized[$key] = $this->sanitizeStructuredValueByKey($item, $normalized);
            }

            return $sanitized;
        }

        if (is_scalar($value)) {
            $text = $this->sanitizeLinkText((string) $value);
            $text = preg_replace('/(smtp_password|mail_password|api_key|token|file_path|physical_path|raw_xml|raw_json|pdh_raw|group_code|supplier_cost|subcontractor_cost|profit|[A-Z]:\\\\|\/var\/)/iu', '[hidden]', $text) ?? $text;

            return Str::limit(trim($text), 500);
        }

        return null;
    }

    private function sanitizeStructuredValueByKey(mixed $value, string $key): mixed
    {
        if (is_scalar($value) && $this->isSensitiveLinkMetaKey($key) && $this->containsPublicApprovalLink((string) $value)) {
            return self::HIDDEN_PUBLIC_LINK_LABEL;
        }

        return $this->sanitizeStructured($value);
    }

    private function sanitizeLinkText(string $value): string
    {
        if ($this->containsEncodedPublicApprovalLink($value)) {
            return self::HIDDEN_PUBLIC_LINK_LABEL;
        }

        $patterns = [
            '~https?://[^\s"\'<>]*/(?:teklif|grafik)/onay/[A-Za-z0-9_-]+[^\s"\'<>]*~iu',
            '~/(?:teklif|grafik)/onay/[A-Za-z0-9_-]+(?:[/?][^\s"\'<>]*)?~iu',
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, self::HIDDEN_PUBLIC_LINK_LABEL, $value) ?? $value;
        }

        return $value;
    }

    private function containsPublicApprovalLink(string $value): bool
    {
        return preg_match('~/(?:teklif|grafik)/onay/[A-Za-z0-9_-]+~iu', $value) === 1
            || $this->containsEncodedPublicApprovalLink($value);
    }

    private function containsEncodedPublicApprovalLink(string $value): bool
    {
        if (!str_contains($value, '%')) {
            return false;
        }

        return preg_match('~/(?:teklif|grafik)/onay/[A-Za-z0-9_-]+~iu', rawurldecode($value)) === 1;
    }

    private function isSensitiveLinkMetaKey(string $key): bool
    {
        return in_array($key, [
            'url',
            'public_link',
            'public_quote_url',
            'public_quote_approval_url',
            'approval_url',
        ], true);
    }
}
