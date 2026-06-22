<?php

namespace App\Services;

use App\Mail\CustomerPortalInviteMail;
use App\Mail\CustomerPortalPasswordResetMail;
use App\Models\CustomerPortalUser;
use App\Models\NotificationTemplate;
use App\Models\TenantAccount;
use App\Models\User;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\TenantNotificationSettingsService;
use App\Services\Notifications\TenantSmtpMailerService;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Throwable;

class CustomerPortalMailService
{
    private const MAILER_NAME = 'customer_portal_runtime';

    public function __construct(
        protected TenantNotificationSettingsService $notificationSettingsService,
        protected TenantSmtpMailerService $smtpMailerService,
        protected NotificationDispatchService $dispatchService,
    ) {
    }

    public function sendInvite(TenantAccount $tenant, CustomerPortalUser $portalUser, string $inviteUrl, ?User $actor = null): string
    {
        return $this->sendPortalMail(
            tenant: $tenant,
            portalUser: $portalUser,
            notificationKey: 'customer_portal_invite_sent',
            subject: CustomerPortalInviteMail::SUBJECT,
            messagePreview: 'Portal davet maili gönderildi.',
            mailable: new CustomerPortalInviteMail($tenant, $portalUser, $inviteUrl, '7 gün'),
            actor: $actor
        );
    }

    public function sendPasswordReset(TenantAccount $tenant, CustomerPortalUser $portalUser, string $resetUrl, ?User $actor = null): string
    {
        return $this->sendPortalMail(
            tenant: $tenant,
            portalUser: $portalUser,
            notificationKey: 'customer_portal_password_reset_requested',
            subject: CustomerPortalPasswordResetMail::SUBJECT,
            messagePreview: 'Portal şifre yenileme maili gönderildi.',
            mailable: new CustomerPortalPasswordResetMail($tenant, $portalUser, $resetUrl, '2 saat'),
            actor: $actor
        );
    }

    public function logPasswordChanged(TenantAccount $tenant, CustomerPortalUser $portalUser, ?User $actor = null): void
    {
        $this->dispatchService->logSent($this->baseLogPayload($tenant, $portalUser, $actor, [
            'notification_key' => 'customer_portal_password_changed',
            'subject' => 'Portal şifresi güncellendi',
            'message_preview' => 'Portal kullanıcısı şifresini güncelledi.',
            'dispatch_mode' => 'portal_security',
        ]));
    }

    private function sendPortalMail(
        TenantAccount $tenant,
        CustomerPortalUser $portalUser,
        string $notificationKey,
        string $subject,
        string $messagePreview,
        Mailable $mailable,
        ?User $actor = null,
    ): string {
        if (! $this->notificationSettingsService->isEmailEnabled($tenant) || blank($portalUser->email)) {
            $this->dispatchService->logSkipped($this->baseLogPayload($tenant, $portalUser, $actor, [
                'notification_key' => $notificationKey,
                'subject' => $subject,
                'message_preview' => $messagePreview,
                'error_message' => 'SMTP aktif değil veya e-posta kanalı kapalı.',
                'dispatch_mode' => 'portal_mail',
            ]));

            return 'skipped';
        }

        Config::set('mail.mailers.' . self::MAILER_NAME, $this->smtpMailerService->buildMailerConfig($tenant));
        Mail::forgetMailers();

        try {
            Mail::mailer(self::MAILER_NAME)
                ->to($portalUser->email)
                ->send($mailable);

            $this->dispatchService->logSent($this->baseLogPayload($tenant, $portalUser, $actor, [
                'notification_key' => $notificationKey,
                'subject' => $subject,
                'message_preview' => $messagePreview,
                'dispatch_mode' => 'portal_mail',
            ]));

            return 'sent';
        } catch (Throwable $exception) {
            $diagnostic = $this->smtpMailerService->buildMailDiagnostic($exception);

            $this->dispatchService->logFailed($this->baseLogPayload($tenant, $portalUser, $actor, [
                'notification_key' => $notificationKey,
                'subject' => $subject,
                'message_preview' => $messagePreview,
                'error_message' => $diagnostic['error_message'],
                'provider_response' => $diagnostic['provider_response'],
                'response_code' => $diagnostic['response_code'],
                'dispatch_mode' => 'portal_mail',
            ]));

            return 'failed';
        } finally {
            Config::offsetUnset('mail.mailers.' . self::MAILER_NAME);
            Mail::forgetMailers();
        }
    }

    private function baseLogPayload(TenantAccount $tenant, CustomerPortalUser $portalUser, ?User $actor, array $overrides = []): array
    {
        return array_merge([
            'tenant_account_id' => $tenant->id,
            'notification_key' => 'customer_portal_notice',
            'template_id' => null,
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'audience_type' => NotificationTemplate::AUDIENCE_CUSTOMER,
            'recipient_type' => 'customer',
            'recipient_name' => $portalUser->safeDisplayName(),
            'recipient_email' => $portalUser->email,
            'related_type' => CustomerPortalUser::class,
            'related_id' => $portalUser->id,
            'created_by' => $actor?->id,
            'meta_json' => [
                'operation' => 'customer_portal_mail',
            ],
        ], $overrides);
    }
}
