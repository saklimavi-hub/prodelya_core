<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Route;

class GraphicApprovalRequest extends Model
{
    use HasFactory;

    public const STATUS_WAITING = 'waiting';
    public const STATUS_VIEWED = 'viewed';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REVISION_REQUESTED = 'revision_requested';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_account_id',
        'order_id',
        'order_item_id',
        'order_item_print_id',
        'order_item_print_graphic_id',
        'work_form_id',
        'attachment_id',
        'customer_company_id',
        'contact_name',
        'contact_email',
        'contact_phone',
        'token',
        'status',
        'customer_note',
        'viewed_at',
        'responded_at',
        'approved_at',
        'revision_requested_at',
        'expires_at',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'meta_json',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
        'responded_at' => 'datetime',
        'approved_at' => 'datetime',
        'revision_requested_at' => 'datetime',
        'expires_at' => 'datetime',
        'cancelled_at' => 'datetime',
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
        return $this->belongsTo(OrderItemPrint::class);
    }

    public function graphic(): BelongsTo
    {
        return $this->belongsTo(OrderItemPrintGraphic::class, 'order_item_print_graphic_id');
    }

    public function workForm(): BelongsTo
    {
        return $this->belongsTo(OrderItemWorkForm::class, 'work_form_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(OrderItemWorkFormAttachment::class, 'attachment_id');
    }

    public function customerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'customer_company_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_WAITING, self::STATUS_VIEWED], true) && ! $this->isExpired();
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

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED
            || ($this->expires_at !== null && $this->expires_at->isPast() && $this->status !== self::STATUS_CANCELLED);
    }

    public function canRespond(): bool
    {
        return in_array($this->status, [self::STATUS_WAITING, self::STATUS_VIEWED], true) && ! $this->isExpired();
    }

    public function safeStatusLabel(): string
    {
        return self::statusLabels()[$this->status]
            ?? ucfirst(str_replace('_', ' ', (string) $this->status));
    }

    public function publicUrl(): ?string
    {
        foreach ([
            'public.graphics.approval.show',
            'public.graphic-approvals.show',
            'public.graphic-approval.show',
        ] as $routeName) {
            if (Route::has($routeName)) {
                return route($routeName, ['token' => $this->token]);
            }
        }

        return null;
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_WAITING => 'Bekliyor',
            self::STATUS_VIEWED => 'Görüntülendi',
            self::STATUS_APPROVED => 'Onaylandı',
            self::STATUS_REVISION_REQUESTED => 'Revize İstendi',
            self::STATUS_EXPIRED => 'Süresi Doldu',
            self::STATUS_CANCELLED => 'İptal Edildi',
        ];
    }
}
