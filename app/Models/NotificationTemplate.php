<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationTemplate extends Model
{
    use HasFactory;

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_WHATSAPP_LINK = 'whatsapp_link';
    public const CHANNEL_INTERNAL = 'internal';
    public const CHANNEL_SMS = 'sms';

    public const AUDIENCE_CUSTOMER = 'customer';
    public const AUDIENCE_INTERNAL = 'internal';
    public const AUDIENCE_FINANCE = 'finance';
    public const AUDIENCE_SALES_OWNER = 'sales_owner';
    public const AUDIENCE_ADMIN = 'admin';

    protected $fillable = [
        'tenant_account_id',
        'notification_key',
        'channel',
        'audience_type',
        'title',
        'subject',
        'body',
        'is_active',
        'variables_json',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'variables_json' => 'array',
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

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_account_id', $tenantId);
    }

    public function isEmail(): bool
    {
        return $this->channel === self::CHANNEL_EMAIL;
    }

    public function isWhatsappLink(): bool
    {
        return $this->channel === self::CHANNEL_WHATSAPP_LINK;
    }

    public function isInternal(): bool
    {
        return $this->channel === self::CHANNEL_INTERNAL;
    }

    public function isSms(): bool
    {
        return $this->channel === self::CHANNEL_SMS;
    }

    public function isCustomerAudience(): bool
    {
        return $this->audience_type === self::AUDIENCE_CUSTOMER;
    }

    public function isFinanceAudience(): bool
    {
        return $this->audience_type === self::AUDIENCE_FINANCE;
    }

    public function isSystemTemplate(): bool
    {
        return $this->tenant_account_id === null;
    }

    public function safeChannelLabel(): string
    {
        return self::channelLabels()[$this->channel]
            ?? ucfirst(str_replace('_', ' ', (string) $this->channel));
    }

    public function safeAudienceLabel(): string
    {
        return self::audienceLabels()[$this->audience_type]
            ?? ucfirst(str_replace('_', ' ', (string) $this->audience_type));
    }

    public static function channelLabels(): array
    {
        return [
            self::CHANNEL_EMAIL => 'E-posta',
            self::CHANNEL_WHATSAPP_LINK => 'WhatsApp Hazır Mesaj',
            self::CHANNEL_INTERNAL => 'İç Bildirim',
            self::CHANNEL_SMS => 'SMS',
        ];
    }

    public static function audienceLabels(): array
    {
        return [
            self::AUDIENCE_CUSTOMER => 'Müşteri',
            self::AUDIENCE_INTERNAL => 'İç Ekip',
            self::AUDIENCE_FINANCE => 'Finans',
            self::AUDIENCE_SALES_OWNER => 'Satış Sorumlusu',
            self::AUDIENCE_ADMIN => 'Yönetici Ekibi',
        ];
    }
}
