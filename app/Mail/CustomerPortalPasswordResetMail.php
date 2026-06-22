<?php

namespace App\Mail;

use App\Models\CustomerPortalUser;
use App\Models\TenantAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CustomerPortalPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public const SUBJECT = 'Müşteri portalı şifre yenileme bağlantısı';

    public function __construct(
        public TenantAccount $tenant,
        public CustomerPortalUser $portalUser,
        public string $resetUrl,
        public string $expiresLabel,
    ) {
    }

    public function build(): static
    {
        return $this
            ->subject(self::SUBJECT)
            ->view('emails.customer-portal-password-reset');
    }
}
