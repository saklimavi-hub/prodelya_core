<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Notifications\TenantNotificationSettingsService;
use App\Services\Notifications\TenantSmtpMailerService;
use App\Services\Notifications\TenantWhatsappLinkService;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationSettingsController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected TenantNotificationSettingsService $notificationSettingsService,
        protected TenantSmtpMailerService $smtpMailerService,
        protected TenantWhatsappLinkService $whatsappLinkService,
    ) {
    }

    public function smtp(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $readiness = $this->notificationSettingsService->readinessSummary($tenant);

        return view('admin.settings.notifications.smtp', [
            'tenant' => $tenant,
            'smtpSettings' => $this->notificationSettingsService->maskSmtpSettingsForDisplay($tenant),
            'smtpReadiness' => $readiness['smtp'],
        ]);
    }

    public function updateSmtp(Request $request): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        $validated = $request->validate([
            'smtp_is_active' => ['nullable', 'boolean'],
            'smtp_host' => ['nullable', 'required_if:smtp_is_active,1', 'string'],
            'smtp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_username' => ['nullable', 'string'],
            'smtp_password' => ['nullable', 'string'],
            'smtp_encryption' => ['nullable', 'in:none,ssl,tls'],
            'smtp_from_name' => ['nullable', 'string', 'max:255'],
            'smtp_from_email' => ['nullable', 'email'],
            'smtp_reply_to_email' => ['nullable', 'email'],
            'smtp_test_email' => ['nullable', 'email'],
        ]);

        $payload = [
            'smtp_is_active' => $request->boolean('smtp_is_active'),
            'smtp_host' => $validated['smtp_host'] ?? null,
            'smtp_port' => $validated['smtp_port'] ?? null,
            'smtp_username' => $validated['smtp_username'] ?? null,
            'smtp_password' => $validated['smtp_password'] ?? null,
            'smtp_encryption' => $validated['smtp_encryption'] ?? 'tls',
            'smtp_from_name' => $validated['smtp_from_name'] ?? null,
            'smtp_from_email' => $validated['smtp_from_email'] ?? null,
            'smtp_reply_to_email' => $validated['smtp_reply_to_email'] ?? null,
            'smtp_test_email' => $validated['smtp_test_email'] ?? null,
        ];

        $this->notificationSettingsService->updateSmtpSettings($tenant, $payload, $request->user());

        return redirect()
            ->route('admin.settings.notifications.smtp')
            ->with('success', 'SMTP ayarları kaydedildi.');
    }

    public function sendTestMail(Request $request): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $smtpConfig = $this->notificationSettingsService->getSmtpConfig($tenant);

        $request->validate([
            'smtp_test_email' => ['nullable', 'email'],
        ]);

        $overrideEmail = $request->input('smtp_test_email');
        $recipient = filled($overrideEmail) ? (string) $overrideEmail : (string) ($smtpConfig['test_email'] ?? '');

        if (!(bool) ($smtpConfig['is_active'] ?? false)) {
            return back()
                ->withErrors(['smtp_is_active' => 'SMTP aktif değil.'])
                ->withInput();
        }

        if (trim($recipient) === '') {
            return back()
                ->withErrors(['smtp_test_email' => 'Test e-posta adresi tanımlı değil.'])
                ->withInput();
        }

        $result = $this->smtpMailerService->sendTestMail(
            $tenant,
            $recipient,
            $request->user()
        );

        $flashKey = $result->status === 'sent' ? 'success' : 'error';
        $flashMessage = $result->status === 'sent'
            ? 'Test mail gönderimi başarıyla denendi.'
            : 'Test mail gönderimi başarısız oldu: ' . ($result->safeDisplayError() ?: 'Güvenli hata özeti alınamadı.');

        return redirect()
            ->route('admin.settings.notifications.smtp')
            ->with($flashKey, $flashMessage);
    }

    public function whatsapp(Request $request): View
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $readiness = $this->notificationSettingsService->readinessSummary($tenant);

        return view('admin.settings.notifications.whatsapp', [
            'tenant' => $tenant,
            'whatsappSettings' => $this->notificationSettingsService->maskWhatsappSettingsForDisplay($tenant),
            'messageTypeOptions' => $this->messageTypeOptions(),
            'whatsappReadiness' => $readiness['whatsapp'],
        ]);
    }

    public function updateWhatsapp(Request $request): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        $validated = $request->validate([
            'whatsapp_is_active' => ['nullable', 'boolean'],
            'whatsapp_default_country_code' => ['nullable', 'string', 'max:8'],
            'whatsapp_sender_label' => ['nullable', 'string', 'max:255'],
            'whatsapp_default_signature' => ['nullable', 'string', 'max:500'],
            'whatsapp_test_phone' => ['nullable', 'string', 'max:40'],
        ]);

        $this->notificationSettingsService->updateChannelSettings($tenant, [
            'whatsapp_is_active' => $request->boolean('whatsapp_is_active'),
            'whatsapp_default_country_code' => $this->notificationSettingsService->normalizeWhatsappCountryCode((string) ($validated['whatsapp_default_country_code'] ?? '90')),
            'whatsapp_sender_label' => $validated['whatsapp_sender_label'] ?? 'Prodelya',
            'whatsapp_default_signature' => $validated['whatsapp_default_signature'] ?? null,
            'whatsapp_test_phone' => $this->whatsappLinkService->normalizePhone($tenant, (string) ($validated['whatsapp_test_phone'] ?? '')),
        ], $request->user());

        return redirect()
            ->route('admin.settings.notifications.whatsapp')
            ->with('success', 'WhatsApp ayarları kaydedildi.');
    }

    public function previewWhatsapp(Request $request): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $validated = $this->validateWhatsappMessageRequest($request);
        $preview = $this->whatsappLinkService->buildPreview($tenant, $validated);

        return redirect()
            ->route('admin.settings.notifications.whatsapp')
            ->withInput()
            ->with('whatsapp_preview', $preview);
    }

    public function createWhatsappLink(Request $request): RedirectResponse
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $validated = $this->validateWhatsappMessageRequest($request);
        $preview = $this->whatsappLinkService->buildPreview($tenant, $validated);
        try {
            $result = $this->whatsappLinkService->createManualLink($tenant, $validated, $request->user());
        } catch (\InvalidArgumentException $exception) {
            return redirect()
                ->route('admin.settings.notifications.whatsapp')
                ->withInput()
                ->withErrors(['recipient_phone' => $exception->getMessage()])
                ->with('whatsapp_preview', $preview);
        }

        return redirect()
            ->route('admin.settings.notifications.whatsapp')
            ->withInput()
            ->with('success', 'WhatsApp linki hazırlandı.')
            ->with('whatsapp_preview', $preview)
            ->with('whatsapp_result', [
                'url' => $result['url'],
                'phone' => $result['phone'],
            ]);
    }

    private function validateWhatsappMessageRequest(Request $request): array
    {
        return $request->validate([
            'customer_name' => ['nullable', 'string', 'max:255'],
            'recipient_phone' => ['required', 'string', 'max:40'],
            'message_type' => ['required', 'in:quote_link,order_tracking,delivery_info,general'],
            'message' => ['nullable', 'string', 'max:2000'],
            'public_link' => ['nullable', 'url', 'max:1000'],
        ]);
    }

    private function messageTypeOptions(): array
    {
        return [
            TenantWhatsappLinkService::TYPE_QUOTE_LINK => 'Teklif linki',
            TenantWhatsappLinkService::TYPE_ORDER_TRACKING => 'Sipariş takip linki',
            TenantWhatsappLinkService::TYPE_DELIVERY_INFO => 'Teslimat bilgisi',
            TenantWhatsappLinkService::TYPE_GENERAL => 'Genel mesaj',
        ];
    }
}
