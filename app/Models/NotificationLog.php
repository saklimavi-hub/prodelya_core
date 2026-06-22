<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\Models\NotificationTemplate;

class NotificationLog extends Model
{
    use HasFactory;

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_WHATSAPP_LINK = 'whatsapp_link';
    public const CHANNEL_INTERNAL = 'internal';
    public const CHANNEL_SMS = 'sms';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_LINK_CREATED = 'link_created';
    public const STATUS_PREVIEW = 'preview';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_account_id',
        'notification_key',
        'template_id',
        'channel',
        'audience_type',
        'recipient_type',
        'recipient_name',
        'recipient_email',
        'recipient_phone',
        'subject',
        'message_preview',
        'status',
        'attempt_count',
        'error_message',
        'related_type',
        'related_id',
        'dispatch_mode',
        'scheduled_at',
        'next_retry_at',
        'provider_response',
        'response_code',
        'meta_json',
        'sent_at',
        'created_by',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'provider_response' => 'array',
        'meta_json' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function account(): BelongsTo
    {
        return $this->tenant();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_id');
    }

    public function related(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'related_type', 'related_id');
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_account_id', $tenantId);
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isWhatsappLinkCreated(): bool
    {
        return $this->channel === self::CHANNEL_WHATSAPP_LINK
            && $this->status === self::STATUS_LINK_CREATED;
    }

    public function safeStatusLabel(): string
    {
        return self::statusLabels()[$this->status]
            ?? ucfirst(str_replace('_', ' ', (string) $this->status));
    }

    public function safeChannelLabel(): string
    {
        return self::channelLabels()[$this->channel]
            ?? ucfirst(str_replace('_', ' ', (string) $this->channel));
    }

    public function safeAudienceLabel(): string
    {
        return NotificationTemplate::audienceLabels()[$this->audience_type]
            ?? ucfirst(str_replace('_', ' ', (string) $this->audience_type));
    }

    public function safeDisplaySubject(): ?string
    {
        return $this->sanitizeDisplayText($this->subject, 255);
    }

    public function safeDisplayPreview(): ?string
    {
        return $this->sanitizeDisplayText($this->message_preview, 500);
    }

    public function safeDisplayError(): ?string
    {
        return $this->sanitizeDisplayText($this->error_message, 500);
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'Bekliyor',
            self::STATUS_SENT => 'Gönderildi',
            self::STATUS_FAILED => 'Başarısız',
            self::STATUS_SKIPPED => 'Atlandı',
            self::STATUS_LINK_CREATED => 'Link Oluşturuldu',
            self::STATUS_PREVIEW => 'Önizleme',
            self::STATUS_CANCELLED => 'İptal',
        ];
    }

    public static function channelLabels(): array
    {
        return [
            self::CHANNEL_EMAIL => 'E-posta',
            self::CHANNEL_WHATSAPP_LINK => 'WhatsApp Link',
            self::CHANNEL_INTERNAL => 'İç Bildirim',
            self::CHANNEL_SMS => 'SMS',
        ];
    }

    private function sanitizeDisplayText(?string $value, int $limit): ?string
    {
        if (!filled($value)) {
            return null;
        }

        $sanitized = (string) $value;
        $sanitized = preg_replace('/(smtp_password|mail_password|api_key|token|file_path|physical_path|raw_xml|raw_json|pdh_raw|group_code|supplier_cost|subcontractor_cost|profit)\s*[:=]?\s*[^\s]+/iu', '[hidden]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/([A-Z]:\\\\[^\s]+|\/var\/[^\s]+)/iu', '[hidden]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/(smtp_password|mail_password|api_key|token|file_path|physical_path|raw_xml|raw_json|pdh_raw|group_code|supplier_cost|subcontractor_cost|profit)/iu', '[hidden]', $sanitized) ?? $sanitized;
        $sanitized = trim(strip_tags($sanitized));

        return mb_substr($sanitized, 0, $limit);
    }
}
