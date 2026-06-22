<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuoteSendSnapshot extends Model
{
    use HasFactory;

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_WHATSAPP_LINK = 'whatsapp_link';
    public const CHANNEL_MANUAL = 'manual';
    public const CHANNEL_PORTAL = 'portal';

    protected $fillable = [
        'tenant_account_id',
        'quote_id',
        'send_no',
        'snapshot_json',
        'summary_json',
        'financial_snapshot_json',
        'sent_channel',
        'sent_to_name',
        'sent_to_email',
        'sent_to_phone',
        'sent_at',
        'created_by',
    ];

    protected $casts = [
        'snapshot_json' => 'array',
        'summary_json' => 'array',
        'financial_snapshot_json' => 'array',
        'sent_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function account(): BelongsTo
    {
        return $this->tenant();
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'quote_id');
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(QuoteApprovalRequest::class, 'quote_send_snapshot_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }

    public function safeSendLabel(): string
    {
        return self::sendLabels()[$this->sent_channel]
            ?? ucfirst(str_replace('_', ' ', (string) $this->sent_channel));
    }

    public function publicReferenceLabel(): string
    {
        $quoteNumber = data_get($this->snapshot_json, 'quote_number')
            ?: $this->quote?->document_number
            ?: 'Teklif';

        return sprintf('%s / Gönderim %d', $quoteNumber, (int) $this->send_no);
    }

    public static function sendLabels(): array
    {
        return [
            self::CHANNEL_EMAIL => 'E-posta',
            self::CHANNEL_WHATSAPP_LINK => 'WhatsApp Link',
            self::CHANNEL_MANUAL => 'Manuel',
            self::CHANNEL_PORTAL => 'Portal',
        ];
    }
}
