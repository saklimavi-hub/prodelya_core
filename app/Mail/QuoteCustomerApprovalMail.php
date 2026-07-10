<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\TenantAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class QuoteCustomerApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public TenantAccount $tenant,
        public Order $quote,
        public string $customerName,
        public string $publicApprovalUrl,
        public string $validUntilLabel,
        public string $grandTotalLabel,
    ) {
    }

    public function build(): static
    {
        return $this
            ->subject('Prodelya Teklifiniz: ' . $this->quote->document_number)
            ->view('emails.quote-customer-approval');
    }
}
