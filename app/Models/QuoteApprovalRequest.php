<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteApprovalRequest extends Model
{
    use HasFactory;

    public const STATUS_WAITING = 'waiting';
    public const STATUS_VIEWED = 'viewed';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REVISION_REQUESTED = 'revision_requested';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_account_id',
        'quote_id',
        'quote_send_snapshot_id',
        'customer_company_id',
        'contact_name',
        'contact_email',
        'contact_phone',
        'token',
        'status',
        'customer_note',
        'viewed_at',
        'responded_at',
        'expires_at',
        'created_by',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
        'responded_at' => 'datetime',
        'expires_at' => 'datetime',
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

    public function sendSnapshot(): BelongsTo
    {
        return $this->belongsTo(QuoteSendSnapshot::class, 'quote_send_snapshot_id');
    }

    public function customerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'customer_company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isWaiting(): bool
    {
        return $this->status === self::STATUS_WAITING;
    }

    public function isViewed(): bool
    {
        return $this->status === self::STATUS_VIEWED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRevisionRequested(): bool
    {
        return $this->status === self::STATUS_REVISION_REQUESTED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED
            || ($this->expires_at !== null && $this->expires_at->isPast() && $this->status !== self::STATUS_CANCELLED);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_WAITING, self::STATUS_VIEWED], true)
            && ! $this->isExpired();
    }

    public function safeStatusLabel(): string
    {
        return self::statusLabels()[$this->status]
            ?? ucfirst(str_replace('_', ' ', (string) $this->status));
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_WAITING => 'Bekliyor',
            self::STATUS_VIEWED => 'Görüntülendi',
            self::STATUS_APPROVED => 'Onaylandı',
            self::STATUS_REVISION_REQUESTED => 'Revize İstendi',
            self::STATUS_REJECTED => 'Reddedildi',
            self::STATUS_EXPIRED => 'Süresi Doldu',
            self::STATUS_CANCELLED => 'İptal Edildi',
        ];
    }
}
