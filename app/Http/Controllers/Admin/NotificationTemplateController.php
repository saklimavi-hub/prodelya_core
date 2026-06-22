<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationEventCatalogService;
use App\Services\Notifications\NotificationTemplateDefaultSeederService;
use App\Services\Notifications\NotificationTemplateService;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NotificationTemplateController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected NotificationEventCatalogService $eventCatalogService,
        protected NotificationTemplateService $notificationTemplateService,
        protected NotificationTemplateDefaultSeederService $defaultSeederService,
    ) {
    }

    public function index(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $this->defaultSeederService->syncTenantDefaultTemplates($tenant);
        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:120'],
            'channel' => ['nullable', 'string', 'max:40'],
            'audience' => ['nullable', 'string', 'max:40'],
            'active' => ['nullable', 'in:active,passive'],
            'scope' => ['nullable', 'in:all,tenant,system'],
        ]);

        $query = NotificationTemplate::query()
            ->where(function ($builder) use ($tenant, $filters) {
                $scope = $filters['scope'] ?? 'all';

                if ($scope === 'tenant') {
                    $builder->where('tenant_account_id', $tenant->id);

                    return;
                }

                if ($scope === 'system') {
                    $builder->whereNull('tenant_account_id');

                    return;
                }

                $builder->where('tenant_account_id', $tenant->id)
                    ->orWhereNull('tenant_account_id');
            })
            ->latest();

        if (!empty($filters['event'])) {
            $query->where('notification_key', $this->eventCatalogService->normalizeEventKey($filters['event']));
        }

        if (!empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }

        if (!empty($filters['audience'])) {
            $query->where('audience_type', $filters['audience']);
        }

        if (($filters['active'] ?? null) === 'active') {
            $query->where('is_active', true);
        } elseif (($filters['active'] ?? null) === 'passive') {
            $query->where('is_active', false);
        }

        $templates = $query->paginate(20)->withQueryString();

        return view('admin.notifications.templates.index', [
            'tenant' => $tenant,
            'templates' => $templates,
            'filters' => [
                'event' => $filters['event'] ?? null,
                'channel' => $filters['channel'] ?? null,
                'audience' => $filters['audience'] ?? null,
                'active' => $filters['active'] ?? null,
                'scope' => $filters['scope'] ?? 'all',
            ],
            'eventOptions' => $this->eventCatalogService->eventOptionsForAdmin(),
            'channelOptions' => NotificationTemplate::channelLabels(),
            'audienceOptions' => $this->allowedAudienceOptions(),
            'eventLabels' => $this->eventLabelMap($templates->getCollection()),
        ]);
    }

    public function create(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $this->defaultSeederService->syncTenantDefaultTemplates($tenant);

        return $this->renderForm($tenant, new NotificationTemplate(), [
            'notification_key' => (string) $request->query('event', ''),
            'channel' => (string) $request->query('channel', NotificationTemplate::CHANNEL_EMAIL),
            'audience_type' => (string) $request->query('audience', NotificationTemplate::AUDIENCE_CUSTOMER),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $payload = $this->validatedPayload($request);

        $template = NotificationTemplate::query()->firstOrNew([
            'tenant_account_id' => $tenant->id,
            'notification_key' => $payload['notification_key'],
            'channel' => $payload['channel'],
            'audience_type' => $payload['audience_type'],
        ]);

        $isExisting = $template->exists;
        $template->fill([
            'title' => $payload['title'],
            'subject' => $payload['subject'],
            'body' => $payload['body'],
            'is_active' => $payload['is_active'],
            'variables_json' => $payload['variables_json'],
            'updated_by' => $request->user()?->id,
        ]);

        if (!$isExisting) {
            $template->created_by = $request->user()?->id;
        }

        $template->save();

        return redirect()
            ->route('admin.notifications.templates.index')
            ->with('success', $isExisting ? 'Bildirim şablonu güncellendi.' : 'Bildirim şablonu kaydedildi.');
    }

    public function edit(Request $request, NotificationTemplate $template): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        abort_unless($template->tenant_account_id === $tenant->id, 404);
        $this->defaultSeederService->syncTenantDefaultTemplates($tenant);

        return $this->renderForm($tenant, $template);
    }

    public function update(Request $request, NotificationTemplate $template): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        abort_unless($template->tenant_account_id === $tenant->id, 404);

        $payload = $this->validatedPayload($request, $template);

        $template->fill([
            'notification_key' => $payload['notification_key'],
            'channel' => $payload['channel'],
            'audience_type' => $payload['audience_type'],
            'title' => $payload['title'],
            'subject' => $payload['subject'],
            'body' => $payload['body'],
            'is_active' => $payload['is_active'],
            'variables_json' => $payload['variables_json'],
            'updated_by' => $request->user()?->id,
        ]);
        $template->save();

        return redirect()
            ->route('admin.notifications.templates.index')
            ->with('success', 'Bildirim şablonu güncellendi.');
    }

    public function preview(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $payload = $this->validatedPayload($request, null, true);

        $template = new NotificationTemplate([
            'tenant_account_id' => $tenant->id,
            'notification_key' => $payload['notification_key'],
            'channel' => $payload['channel'],
            'audience_type' => $payload['audience_type'],
            'title' => $payload['title'],
            'subject' => $payload['subject'],
            'body' => $payload['body'],
            'is_active' => $payload['is_active'],
        ]);

        $context = $this->previewContext($payload['audience_type'], $payload['sample_context']);
        $rendered = $this->notificationTemplateService->render($template, $context, $payload['audience_type']);

        $preview = [
            'subject' => $rendered['subject'],
            'body' => $rendered['body'],
            'allowed_variables' => $this->notificationTemplateService->allowedVariablesForAudience($payload['audience_type']),
            'blocked_variables' => $rendered['blocked_variables'],
            'missing_variables' => $rendered['missing_variables'],
            'removed_context_keys' => array_values(array_diff(array_keys($payload['sample_context']), array_keys($context))),
        ];

        $existingTemplate = null;
        if ($request->filled('template_id')) {
            $existingTemplate = NotificationTemplate::query()
                ->where('tenant_account_id', $tenant->id)
                ->find($request->integer('template_id'));
        }

        return $this->renderForm($tenant, $existingTemplate ?? new NotificationTemplate(), [
            'template_id' => $existingTemplate?->id,
            'notification_key' => $payload['notification_key'],
            'channel' => $payload['channel'],
            'audience_type' => $payload['audience_type'],
            'title' => $payload['title'],
            'subject' => $payload['subject'],
            'body' => $payload['body'],
            'is_active' => $payload['is_active'],
            'sample_context' => json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ], $preview);
    }

    public function syncDefaults(Request $request): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $result = $this->defaultSeederService->syncTenantDefaultTemplates($tenant);

        return redirect()
            ->route('admin.notifications.templates.index')
            ->with(
                'success',
                $result['created_count'] > 0
                    ? $result['created_count'] . ' eksik şablon oluşturuldu.'
                    : 'Tüm varsayılan şablonlar hazır.'
            );
    }

    private function renderForm($tenant, NotificationTemplate $template, array $formData = [], ?array $preview = null): View
    {
        $defaults = [
            'template_id' => $template->id,
            'notification_key' => $template->notification_key ?: '',
            'channel' => $template->channel ?: NotificationTemplate::CHANNEL_EMAIL,
            'audience_type' => $template->audience_type ?: NotificationTemplate::AUDIENCE_CUSTOMER,
            'title' => $template->title,
            'subject' => $template->subject,
            'body' => $template->body,
            'is_active' => $template->exists ? (bool) $template->is_active : true,
            'sample_context' => '',
        ];

        $form = array_merge($defaults, $formData);
        if (blank($form['sample_context'] ?? '')) {
            $form['sample_context'] = json_encode(
                $this->previewContext((string) ($form['audience_type'] ?? NotificationTemplate::AUDIENCE_CUSTOMER), []),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            );
        }
        $view = $template->exists ? 'admin.notifications.templates.edit' : 'admin.notifications.templates.create';

        return view($view, [
            'tenant' => $tenant,
            'template' => $template,
            'form' => $form,
            'preview' => $preview,
            'eventOptions' => $this->eventCatalogService->eventOptionsForAdmin(),
            'channelOptions' => config('prodelya_notifications.channels', []),
            'audienceOptions' => $this->allowedAudienceOptions(),
            'variableHelp' => $this->notificationTemplateService->variableHelpForAudience((string) ($form['audience_type'] ?? NotificationTemplate::AUDIENCE_CUSTOMER)),
            'selectedEvent' => $this->eventCatalogService->getEvent((string) ($form['notification_key'] ?? '')),
        ]);
    }

    private function validatedPayload(Request $request, ?NotificationTemplate $template = null, bool $allowSampleContext = false): array
    {
        $rules = [
            'notification_key' => ['required', 'string', 'max:120'],
            'channel' => ['required', 'string', 'in:email,whatsapp_link,internal,sms'],
            'audience_type' => ['required', 'string', 'in:customer,internal,finance,admin,sales_owner'],
            'title' => ['nullable', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];

        if ($allowSampleContext) {
            $rules['sample_context'] = ['nullable', 'string'];
            $rules['template_id'] = ['nullable', 'integer'];
        }

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($request, $template) {
            $normalizedEventKey = $this->eventCatalogService->normalizeEventKey((string) $request->input('notification_key'));
            $event = $this->eventCatalogService->getEvent($normalizedEventKey);
            $channel = (string) $request->input('channel');
            $channelStatus = config('prodelya_notifications.channels.' . $channel . '.status', 'passive');

            if (!$event) {
                $validator->errors()->add('notification_key', 'Geçerli bir olay seçin.');
                return;
            }

            if (($event['status'] ?? 'passive') === 'passive') {
                $validator->errors()->add('notification_key', 'Pasif olaylar için tenant şablonu oluşturulamaz.');
            }

            if ($channel === NotificationTemplate::CHANNEL_SMS || $channelStatus !== 'active') {
                $validator->errors()->add('channel', 'SMS bu fazda pasif olduğu için seçilemez.');
            }

            if (!in_array($channel, $event['allowed_channels'] ?? [], true)) {
                $validator->errors()->add('channel', 'Seçilen kanal bu olay için desteklenmiyor.');
            }

            if ($template && $template->exists) {
                $duplicate = NotificationTemplate::query()
                    ->where('tenant_account_id', $template->tenant_account_id)
                    ->where('notification_key', $normalizedEventKey)
                    ->where('channel', $channel)
                    ->where('audience_type', (string) $request->input('audience_type'))
                    ->whereKeyNot($template->id)
                    ->exists();

                if ($duplicate) {
                    $validator->errors()->add('channel', 'Bu olay, kanal ve hedef kitle için zaten tenant şablonu var.');
                }
            }

            if ($request->filled('sample_context')) {
                try {
                    $decoded = json_decode((string) $request->input('sample_context'), true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($decoded)) {
                        $validator->errors()->add('sample_context', 'Örnek veri JSON nesnesi olmalı.');
                    }
                } catch (\Throwable) {
                    $validator->errors()->add('sample_context', 'Örnek veri geçerli JSON olmalı.');
                }
            }
        });

        $validated = $validator->validate();
        $normalizedEventKey = $this->eventCatalogService->normalizeEventKey((string) $validated['notification_key']);

        $draftTemplate = new NotificationTemplate([
            'tenant_account_id' => $this->tenantResolver->getCurrentTenant($request)?->id,
            'notification_key' => $normalizedEventKey,
            'channel' => $validated['channel'],
            'audience_type' => $validated['audience_type'],
            'title' => $validated['title'] ?? null,
            'subject' => $validated['subject'] ?? null,
            'body' => $validated['body'],
        ]);

        $variableInfo = $this->notificationTemplateService->validateTemplateVariables($draftTemplate);

        return [
            'notification_key' => $normalizedEventKey,
            'channel' => $validated['channel'],
            'audience_type' => $validated['audience_type'],
            'title' => $validated['title'] ?? null,
            'subject' => $validated['subject'] ?? null,
            'body' => $validated['body'],
            'is_active' => $request->boolean('is_active', true),
            'variables_json' => $variableInfo['used_variables'],
            'sample_context' => $this->parseSampleContext((string) ($validated['sample_context'] ?? '')),
        ];
    }

    private function parseSampleContext(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'sample_context' => 'Örnek veri geçerli JSON olmalı.',
            ]);
        }

        if (!is_array($decoded)) {
            throw ValidationException::withMessages([
                'sample_context' => 'Örnek veri JSON nesnesi olmalı.',
            ]);
        }

        $normalized = [];
        foreach ($decoded as $key => $item) {
            if (!is_scalar($item) && $item !== null) {
                continue;
            }

            $normalized[(string) $key] = $item;
        }

        return $normalized;
    }

    private function previewContext(string $audienceType, array $sampleContext): array
    {
        $seed = match ($audienceType) {
            NotificationTemplate::AUDIENCE_FINANCE => [
                'customer_name' => 'ABC İnşaat',
                'company_name' => 'ABC İnşaat',
                'order_number' => 'SP-2026-0042',
                'payment_amount' => '1500',
                'payment_currency' => 'TL',
                'paid_total' => '4500',
                'balance_due' => '750',
                'status_label' => 'Ödeme Alındı',
            ],
            NotificationTemplate::AUDIENCE_INTERNAL, NotificationTemplate::AUDIENCE_ADMIN, NotificationTemplate::AUDIENCE_SALES_OWNER => [
                'company_name' => 'ABC İnşaat',
                'order_number' => 'SP-2026-0042',
                'work_form_number' => 'WF-2026-0042',
                'status_label' => 'Hazır',
                'product_summary' => 'Logo baskılı ürün',
            ],
            default => [
                'customer_name' => 'ABC İnşaat',
                'company_name' => 'ABC İnşaat',
                'quote_number' => 'TK-2026-0042',
                'order_number' => 'SP-2026-0042',
                'status_label' => 'Hazır',
                'public_quote_approval_url' => 'https://prodelya.test/teklif/onay/abc123',
                'public_tracking_url' => 'https://prodelya.test/takip/abc123',
                'product_summary' => 'Logo baskılı ürün',
            ],
        };

        $allowed = $this->notificationTemplateService->allowedVariablesForAudience($audienceType);
        $merged = array_merge($seed, $sampleContext);
        $safeContext = [];

        foreach ($merged as $key => $value) {
            if (!in_array((string) $key, $allowed, true)) {
                continue;
            }

            $safeContext[(string) $key] = is_scalar($value) || $value === null
                ? (string) $value
                : '';
        }

        return $safeContext;
    }

    private function eventLabelMap($templates): array
    {
        $labels = [];

        foreach ($templates as $template) {
            $labels[$template->id] = $this->eventCatalogService->getEvent((string) $template->notification_key)['label']
                ?? Str::headline((string) $template->notification_key);
        }

        return $labels;
    }

    private function allowedAudienceOptions(): array
    {
        return Arr::only([
            NotificationTemplate::AUDIENCE_CUSTOMER => 'Müşteri',
            NotificationTemplate::AUDIENCE_INTERNAL => 'İç Ekip',
            NotificationTemplate::AUDIENCE_FINANCE => 'Finans',
            NotificationTemplate::AUDIENCE_ADMIN => 'Yönetici Ekibi',
            NotificationTemplate::AUDIENCE_SALES_OWNER => 'Satış Sorumlusu',
        ], [
            NotificationTemplate::AUDIENCE_CUSTOMER,
            NotificationTemplate::AUDIENCE_INTERNAL,
            NotificationTemplate::AUDIENCE_FINANCE,
            NotificationTemplate::AUDIENCE_ADMIN,
            NotificationTemplate::AUDIENCE_SALES_OWNER,
        ]);
    }
}
