<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderRevisionChange extends Model
{
    use HasFactory;

    public const DECISION_NO_CHANGE = 'no_change';
    public const DECISION_APPLICABLE = 'applicable';
    public const DECISION_CONTROLLED_APPLICABLE = 'controlled_applicable';
    public const DECISION_LOCKED = 'locked';
    public const DECISION_MANUAL_REVIEW = 'manual_review';

    public const APPLY_STATUS_PENDING = 'pending';
    public const APPLY_STATUS_APPLIED = 'applied';
    public const APPLY_STATUS_SKIPPED = 'skipped';
    public const APPLY_STATUS_REJECTED = 'rejected';
    public const APPLY_STATUS_BLOCKED = 'blocked';
    public const APPLY_STATUS_MANUAL_REQUIRED = 'manual_required';

    protected $fillable = [
        'tenant_account_id',
        'order_revision_id',
        'order_id',
        'order_item_id',
        'order_item_print_id',
        'change_group',
        'field_key',
        'old_value',
        'new_value',
        'decision',
        'apply_status',
        'reason',
        'applied_at',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'decision' => 'string',
        'apply_status' => 'string',
        'applied_at' => 'datetime',
    ];

    public function tenantAccount(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(OrderRevision::class, 'order_revision_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function orderItemPrint(): BelongsTo
    {
        return $this->belongsTo(OrderItemPrint::class, 'order_item_print_id');
    }

    public static function decisionLabels(): array
    {
        return [
            self::DECISION_NO_CHANGE => 'Değişiklik Yok',
            self::DECISION_APPLICABLE => 'Uygulanabilir',
            self::DECISION_CONTROLLED_APPLICABLE => 'Kontrollü Uygulanabilir',
            self::DECISION_LOCKED => 'Kilitli',
            self::DECISION_MANUAL_REVIEW => 'Manuel Kontrol Gerekli',
        ];
    }

    public static function applyStatusLabels(): array
    {
        return [
            self::APPLY_STATUS_PENDING => 'Bekliyor',
            self::APPLY_STATUS_APPLIED => 'Uygulandı',
            self::APPLY_STATUS_SKIPPED => 'Atlandı',
            self::APPLY_STATUS_REJECTED => 'Reddedildi',
            self::APPLY_STATUS_BLOCKED => 'Engellendi',
            self::APPLY_STATUS_MANUAL_REQUIRED => 'Manuel Kontrol Gerekli',
        ];
    }

    public function decisionLabel(): string
    {
        return self::decisionLabels()[$this->decision] ?? ucfirst(str_replace('_', ' ', (string) $this->decision));
    }

    public function applyStatusLabel(): string
    {
        return self::applyStatusLabels()[$this->apply_status] ?? ucfirst(str_replace('_', ' ', (string) $this->apply_status));
    }
}
