<?php

namespace App\Services\Notifications;

use App\Mail\QuoteCustomerApprovalMail;
use App\Mail\TenantSmtpTestMail;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Throwable;

class TenantSmtpMailerService
{
    private const SMTP_TEST_NOTIFICATION_KEY = 'smtp_test_mail';
    private const MAILER_NAME = 'tenant_smtp_runtime';

    public function __construct(
        protected TenantNotificationSettingsService $settingsService,
        protected NotificationDispatchService $dispatchService,
    ) {
    }

    public function sendTestMail(TenantAccount $tenant, ?string $to = null, ?User $user = null): NotificationLog
    {
        $config = $this->settingsService->getSmtpConfig($tenant);
        $recipient = trim((string) ($to ?: ($config['test_email'] ?? '')));

        if (!(bool) ($config['is_active'] ?? false)) {
            return $this->dispatchService->logFailed($this->baseLogPayload($tenant, $recipient, $user, [
                'error_message' => 'SMTP aktif değil.',
                'dispatch_mode' => 'test',
            ]));
        }

        if ($recipient === '') {
            return $this->dispatchService->logFailed($this->baseLogPayload($tenant, $recipient, $user, [
                'error_message' => 'Test e-posta adresi tanımlı değil.',
                'dispatch_mode' => 'test',
            ]));
        }

        Config::set('mail.mailers.' . self::MAILER_NAME, $this->buildMailerConfig($tenant));
        Mail::forgetMailers();

        try {
            Mail::mailer(self::MAILER_NAME)
                ->to($recipient)
                ->send(new TenantSmtpTestMail($tenant, $config['from_name'] ?: $tenant->name));

            return $this->dispatchService->logSent($this->baseLogPayload($tenant, $recipient, $user, [
                'subject' => TenantSmtpTestMail::SUBJECT,
                'message_preview' => 'Prodelya SMTP test maili gönderildi.',
                'dispatch_mode' => 'test',
            ]));
        } catch (Throwable $exception) {
            $diagnostic = $this->buildMailDiagnostic($exception);

            return $this->dispatchService->logFailed($this->baseLogPayload($tenant, $recipient, $user, [
                'subject' => TenantSmtpTestMail::SUBJECT,
                'message_preview' => 'Prodelya SMTP test maili gönderilemedi.',
                'error_message' => $diagnostic['error_message'],
                'provider_response' => $diagnostic['provider_response'],
                'response_code' => $diagnostic['response_code'],
                'meta_json' => [
                    'operation' => 'smtp_test_mail',
                    'diagnostic_category' => $diagnostic['category'],
                ],
                'dispatch_mode' => 'test',
            ]));
        } finally {
            Config::offsetUnset('mail.mailers.' . self::MAILER_NAME);
            Mail::forgetMailers();
        }
    }

    public function sendQuoteApprovalMail(
        TenantAccount $tenant,
        Order $quote,
        array $recipientData,
        string $publicApprovalUrl,
        ?User $user = null,
        bool $forcePreview = false
    ): NotificationLog {
        $config = $this->settingsService->getSmtpConfig($tenant);
        $recipientEmail = trim((string) ($recipientData['contact_email'] ?? ''));
        $recipientName = trim((string) ($recipientData['contact_name'] ?? ($quote->customer?->legal_name ?: 'Müşterimiz')));

        $mailable = new QuoteCustomerApprovalMail(
            tenant: $tenant,
            quote: $quote,
            customerName: $recipientName !== '' ? $recipientName : 'Müşterimiz',
            publicApprovalUrl: $publicApprovalUrl,
            validUntilLabel: $quote->valid_until?->format('d.m.Y') ?: '-',
            grandTotalLabel: number_format((float) $quote->grand_total, 2, ',', '.') . ' ' . ($quote->currency ?: 'TL'),
        );

        $payload = $this->baseQuoteLogPayload($tenant, $quote, $recipientData, $user, [
            'subject' => 'Prodelya Teklifiniz: ' . $quote->document_number,
        ]);

        if ($forcePreview || $this->shouldUsePreviewMode($tenant, $config)) {
            return $this->dispatchService->dispatchEmailPreview($tenant, array_merge($payload, [
                'body' => $this->renderQuoteApprovalPreview($mailable),
                'dispatch_mode' => 'preview',
            ]), $user);
        }

        Config::set('mail.mailers.' . self::MAILER_NAME, $this->buildMailerConfig($tenant));
        Mail::forgetMailers();

        try {
            Mail::mailer(self::MAILER_NAME)
                ->to($recipientEmail)
                ->send($mailable);

            return $this->dispatchService->logSent(array_merge($payload, [
                'message_preview' => 'Teklif müşteriye e-posta olarak gönderildi.',
                'dispatch_mode' => 'sync',
            ]));
        } catch (Throwable $exception) {
            $diagnostic = $this->buildMailDiagnostic($exception);

            return $this->dispatchService->logFailed(array_merge($payload, [
                'message_preview' => 'Teklif müşteri e-postası gönderilemedi.',
                'error_message' => $diagnostic['error_message'],
                'provider_response' => $diagnostic['provider_response'],
                'response_code' => $diagnostic['response_code'],
                'meta_json' => [
                    'operation' => 'quote_customer_mail',
                    'diagnostic_category' => $diagnostic['category'],
                ],
                'dispatch_mode' => 'sync',
            ]));
        } finally {
            Config::offsetUnset('mail.mailers.' . self::MAILER_NAME);
            Mail::forgetMailers();
        }
    }

    public function buildMailerConfig(TenantAccount $tenant): array
    {
        $config = $this->settingsService->getSmtpConfig($tenant);
        $encryption = (string) ($config['encryption'] ?? 'tls');

        return [
            'transport' => 'smtp',
            'host' => $config['host'],
            'port' => (int) ($config['port'] ?? 587),
            'username' => $config['username'],
            'password' => $config['password'],
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'auto_tls' => $encryption !== 'none',
            'from' => [
                'address' => $config['from_email'] ?: 'no-reply@prodelya.local',
                'name' => $config['from_name'] ?: $tenant->name,
            ],
            'reply_to' => filled($config['reply_to_email'] ?? null)
                ? [
                    'address' => $config['reply_to_email'],
                    'name' => $config['from_name'] ?: $tenant->name,
                ]
                : null,
        ];
    }

    public function sanitizeMailError(Throwable $exception): string
    {
        return $this->buildMailDiagnostic($exception)['error_message'];
    }

    public function buildMailDiagnostic(Throwable $exception): array
    {
        $message = mb_strtolower(trim($exception->getMessage()));
        $responseCode = $this->extractResponseCode($exception);

        $summary = 'SMTP test maili gönderilemedi.';
        $category = 'unknown';
        $suggestion = 'Ayarları kontrol edip tekrar deneyin.';

        if (
            str_contains($message, 'auth')
            || str_contains($message, 'login')
            || str_contains($message, 'credential')
            || str_contains($message, 'username')
            || str_contains($message, 'password')
            || $responseCode === '535'
        ) {
            $summary = 'SMTP kimlik doğrulaması başarısız oldu.';
            $category = 'authentication_failed';
            $suggestion = 'Kullanıcı adı, şifre ve gerekiyorsa uygulama parolasını kontrol edin.';
        } elseif (
            str_contains($message, 'certificate')
            || str_contains($message, 'peer')
            || str_contains($message, 'self signed')
            || str_contains($message, 'tlsv')
        ) {
            $summary = 'SMTP sertifika veya TLS doğrulama hatası oluştu.';
            $category = 'tls_certificate_error';
            $suggestion = 'Sunucu sertifikasını ve TLS ayarlarını kontrol edin.';
        } elseif (
            str_contains($message, 'starttls')
            || str_contains($message, 'encryption')
            || str_contains($message, 'handshake')
            || str_contains($message, 'ssl')
            || str_contains($message, 'tls')
        ) {
            $summary = 'SMTP port veya şifreleme ayarı uyumsuz görünüyor.';
            $category = 'port_encryption_mismatch';
            $suggestion = '465 için ssl, 587 için tls ayarını deneyin.';
        } elseif (
            str_contains($message, 'from')
            || str_contains($message, 'sender')
            || str_contains($message, 'mail from')
        ) {
            $summary = 'From Email adresi SMTP sunucusu tarafından kabul edilmedi.';
            $category = 'from_email_rejected';
            $suggestion = 'From Email ile SMTP kullanıcı adının aynı olmasını deneyin.';
        } elseif (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            $summary = 'SMTP sunucusundan zamanında yanıt alınamadı.';
            $category = 'timeout';
            $suggestion = 'Sunucuya erişim ve port bilgisini kontrol edin.';
        } elseif (
            str_contains($message, 'connection')
            || str_contains($message, 'could not connect')
            || str_contains($message, 'failed to connect')
            || str_contains($message, 'stream_socket_client')
        ) {
            $summary = 'SMTP bağlantısı kurulamadı.';
            $category = 'connection_failed';
            $suggestion = 'Host, port ve güvenlik duvarı erişimini kontrol edin.';
        }

        $providerResponse = [
            'diagnostic_category' => $category,
            'summary' => $summary,
            'suggestion' => $suggestion,
        ];

        if ($responseCode !== null) {
            $providerResponse['response_code'] = $responseCode;
        }

        return [
            'category' => $category,
            'error_message' => $summary,
            'response_code' => $responseCode,
            'provider_response' => $providerResponse,
        ];
    }

    private function baseLogPayload(TenantAccount $tenant, string $recipientEmail, ?User $user, array $overrides = []): array
    {
        return array_merge([
            'tenant_account_id' => $tenant->id,
            'notification_key' => self::SMTP_TEST_NOTIFICATION_KEY,
            'template_id' => null,
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'audience_type' => NotificationTemplate::AUDIENCE_INTERNAL,
            'recipient_type' => 'email',
            'recipient_name' => 'SMTP Test',
            'recipient_email' => $recipientEmail !== '' ? $recipientEmail : null,
            'subject' => TenantSmtpTestMail::SUBJECT,
            'related_type' => TenantAccount::class,
            'related_id' => $tenant->id,
            'created_by' => $user?->id,
            'meta_json' => [
                'operation' => 'smtp_test_mail',
            ],
        ], $overrides);
    }

    private function baseQuoteLogPayload(TenantAccount $tenant, Order $quote, array $recipientData, ?User $user, array $overrides = []): array
    {
        return array_merge([
            'tenant_account_id' => $tenant->id,
            'notification_key' => 'quote_sent_to_customer',
            'template_id' => null,
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'audience_type' => NotificationTemplate::AUDIENCE_CUSTOMER,
            'recipient_type' => 'customer',
            'recipient_name' => $recipientData['contact_name'] ?? ($quote->customer?->legal_name ?: null),
            'recipient_email' => $recipientData['contact_email'] ?? null,
            'related_type' => $quote->getMorphClass(),
            'related_id' => $quote->id,
            'created_by' => $user?->id,
            'meta_json' => [
                'operation' => 'quote_customer_mail',
            ],
        ], $overrides);
    }

    private function shouldUsePreviewMode(TenantAccount $tenant, array $config): bool
    {
        return !$this->settingsService->isEmailEnabled($tenant)
            || !filled($config['host'] ?? null)
            || !filled($config['from_email'] ?? null);
    }

    private function renderQuoteApprovalPreview(QuoteCustomerApprovalMail $mailable): string
    {
        return View::make('emails.quote-customer-approval', [
            'tenant' => $mailable->tenant,
            'quote' => $mailable->quote,
            'customerName' => $mailable->customerName,
            'publicApprovalUrl' => $mailable->publicApprovalUrl,
            'validUntilLabel' => $mailable->validUntilLabel,
            'grandTotalLabel' => $mailable->grandTotalLabel,
        ])->render();
    }

    private function extractResponseCode(Throwable $exception): ?string
    {
        $message = trim($exception->getMessage());

        if (preg_match('/\b([245]\d{2})\b/u', $message, $matches) === 1) {
            return $matches[1];
        }

        $code = trim((string) $exception->getCode());

        if (preg_match('/^[245]\d{2}$/u', $code) === 1) {
            return $code;
        }

        return null;
    }
}
