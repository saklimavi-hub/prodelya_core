<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderRevision extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVIEW_PENDING = 'review_pending';
    public const STATUS_PARTIALLY_APPLICABLE = 'partially_applicable';
    public const STATUS_READY_TO_APPLY = 'ready_to_apply';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_PARTIALLY_APPLIED = 'partially_applied';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_account_id',
        'order_id',
        'revision_quote_id',
        'revision_number',
        'status',
        'requested_by_user_id',
        'applied_by_user_id',
        'applied_at',
        'rejected_by_user_id',
        'rejected_at',
        'cancelled_by_user_id',
        'cancelled_at',
        'summary',
    ];

    protected $casts = [
        'revision_number' => 'integer',
        'status' => 'string',
        'applied_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'summary' => 'array',
    ];

    public function tenantAccount(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function revisionQuote(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'revision_quote_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(OrderRevisionChange::class, 'order_revision_id');
    }

    public function latestChange(): HasOne
    {
        return $this->hasOne(OrderRevisionChange::class, 'order_revision_id')->latestOfMany();
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Taslak',
            self::STATUS_REVIEW_PENDING => 'Kontrol Bekliyor',
            self::STATUS_PARTIALLY_APPLICABLE => 'Kısmi Uygulanabilir',
            self::STATUS_READY_TO_APPLY => 'Uygulamaya Hazır',
            self::STATUS_APPLIED => 'Uygulandı',
            self::STATUS_PARTIALLY_APPLIED => 'Kısmi Uygulandı',
            self::STATUS_REJECTED => 'Reddedildi',
            self::STATUS_CANCELLED => 'İptal Edildi',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? ucfirst(str_replace('_', ' ', (string) $this->status));
    }
}
