<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemPrintSetupRequirement extends Model
{
    use HasFactory;

    public const TYPE_CLICHE = 'cliche';
    public const TYPE_MOLD = 'mold';
    public const TYPE_FILM = 'film';
    public const TYPE_MONTAGE = 'montage';
    public const TYPE_DIE_CUT = 'die_cut';
    public const TYPE_STENCIL = 'stencil';
    public const TYPE_APPARATUS = 'apparatus';
    public const TYPE_COLOR_SEPARATION = 'color_separation';
    public const TYPE_FOIL_MOLD = 'foil_mold';
    public const TYPE_LASER_TEMPLATE = 'laser_template';
    public const TYPE_OTHER = 'other';

    public const STATUS_PENDING = 'bekliyor';
    public const STATUS_REQUESTED = 'talep_edildi';
    public const STATUS_READY = 'hazir';
    public const STATUS_CANCELLED = 'iptal';
    public const STATUS_NOT_REQUIRED = 'gerekli_degil';

    protected $fillable = [
        'tenant_account_id',
        'order_id',
        'order_item_id',
        'order_item_print_id',
        'setup_type',
        'status',
        'assigned_company_id',
        'assigned_current_account_id',
        'cost',
        'currency',
        'note',
        'completed_at',
        'completed_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function account(): BelongsTo
    {
        return $this->tenant();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function orderItemPrint(): BelongsTo
    {
        return $this->belongsTo(OrderItemPrint::class, 'order_item_print_id');
    }

    public function assignedCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'assigned_company_id');
    }

    public function assignedCurrentAccount(): BelongsTo
    {
        return $this->belongsTo(CurrentAccount::class, 'assigned_current_account_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public static function setupTypeLabels(): array
    {
        return [
            self::TYPE_CLICHE => 'Klişe',
            self::TYPE_MOLD => 'Kalıp',
            self::TYPE_FILM => 'Film',
            self::TYPE_MONTAGE => 'Montaj',
            self::TYPE_DIE_CUT => 'Bıçak',
            self::TYPE_STENCIL => 'Şablon',
            self::TYPE_APPARATUS => 'Aparat',
            self::TYPE_COLOR_SEPARATION => 'Renk Ayrımı',
            self::TYPE_FOIL_MOLD => 'Varak Kalıbı',
            self::TYPE_LASER_TEMPLATE => 'Lazer Şablonu',
            self::TYPE_OTHER => 'Diğer',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'Bekliyor',
            self::STATUS_REQUESTED => 'Talep Edildi',
            self::STATUS_READY => 'Hazır',
            self::STATUS_CANCELLED => 'İptal',
            self::STATUS_NOT_REQUIRED => 'Gerekli Değil',
        ];
    }

    public function safeSetupTypeLabel(): string
    {
        return self::setupTypeLabels()[$this->setup_type]
            ?? ucfirst(str_replace('_', ' ', (string) $this->setup_type));
    }

    public function safeStatusLabel(): string
    {
        return self::statusLabels()[$this->status]
            ?? ucfirst(str_replace('_', ' ', (string) $this->status));
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_REQUESTED], true);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED || $this->cancelled_at !== null;
    }

    public function isRequired(): bool
    {
        return !in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_NOT_REQUIRED], true);
    }

    public function canBeCompleted(): bool
    {
        return !$this->isCancelled() && $this->status !== self::STATUS_READY;
    }

    public function canBeCancelled(): bool
    {
        return !$this->isCancelled();
    }
}
