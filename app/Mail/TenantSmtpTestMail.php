<?php

namespace App\Mail;

use App\Models\TenantAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TenantSmtpTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public const SUBJECT = 'Prodelya SMTP Test Maili';

    public function __construct(
        public TenantAccount $tenant,
        public string $senderLabel,
    ) {
    }

    public function build(): static
    {
        return $this
            ->subject(self::SUBJECT)
            ->view('emails.smtp-test');
    }
}
