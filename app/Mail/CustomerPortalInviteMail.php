<?php

namespace App\Mail;

use App\Models\CustomerPortalUser;
use App\Models\TenantAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CustomerPortalInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public const SUBJECT = 'Müşteri portalı davetiniz hazır';

    public function __construct(
        public TenantAccount $tenant,
        public CustomerPortalUser $portalUser,
        public string $inviteUrl,
        public string $expiresLabel,
    ) {
    }

    public function build(): static
    {
        return $this
            ->subject(self::SUBJECT)
            ->view('emails.customer-portal-invite');
    }
}
