<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItemPrintGraphic extends Model
{
    use HasFactory;

    public const STATUS_WAITING_VISUAL = 'waiting_visual';
    public const STATUS_VISUAL_UPLOADED = 'visual_uploaded';
    public const STATUS_CUSTOMER_APPROVAL_WAITING = 'customer_approval_waiting';
    public const STATUS_REVISION_REQUESTED = 'revision_requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PRODUCTION_READY = 'production_ready';
    public const STATUS_NOT_REQUIRED = 'not_required';

    public const CUSTOMER_APPROVAL_NOT_REQUIRED = 'not_required';
    public const CUSTOMER_APPROVAL_WAITING = 'waiting';
    public const CUSTOMER_APPROVAL_APPROVED = 'approved';
    public const CUSTOMER_APPROVAL_REVISION_REQUESTED = 'revision_requested';
    public const CUSTOMER_APPROVAL_REJECTED = 'rejected';

    protected $fillable = [
        'tenant_account_id',
        'order_id',
        'order_item_id',
        'order_item_print_id',
        'order_item_work_form_id',
        'sequence_code',
        'status',
        'customer_approval_status',
        'latest_attachment_id',
        'graphic_note',
        'customer_note',
        'visibility_default',
        'production_ready_at',
        'approved_at',
        'revision_requested_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'production_ready_at' => 'datetime',
        'approved_at' => 'datetime',
        'revision_requested_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
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

    public function workForm(): BelongsTo
    {
        return $this->belongsTo(OrderItemWorkForm::class, 'order_item_work_form_id');
    }

    public function latestAttachment(): BelongsTo
    {
        return $this->belongsTo(OrderItemWorkFormAttachment::class, 'latest_attachment_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OrderItemWorkFormAttachment::class, 'order_item_print_graphic_id');
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(GraphicApprovalRequest::class, 'order_item_print_graphic_id');
    }

    public function latestApprovalRequest(): HasOne
    {
        return $this->hasOne(GraphicApprovalRequest::class, 'order_item_print_graphic_id')->latestOfMany();
    }

    public function openApprovalRequest(): HasOne
    {
        return $this->hasOne(GraphicApprovalRequest::class, 'order_item_print_graphic_id')
            ->whereIn('status', [
                GraphicApprovalRequest::STATUS_WAITING,
                GraphicApprovalRequest::STATUS_VIEWED,
            ])
            ->latestOfMany();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isWaitingVisual(): bool
    {
        return $this->status === self::STATUS_WAITING_VISUAL;
    }

    public function isVisualUploaded(): bool
    {
        return $this->status === self::STATUS_VISUAL_UPLOADED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isProductionReady(): bool
    {
        return $this->status === self::STATUS_PRODUCTION_READY;
    }

    public function isRevisionRequested(): bool
    {
        return $this->status === self::STATUS_REVISION_REQUESTED;
    }

    public function safeStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_WAITING_VISUAL => 'Görsel Bekliyor',
            self::STATUS_VISUAL_UPLOADED => 'Görsel Eklendi',
            self::STATUS_CUSTOMER_APPROVAL_WAITING => 'Onay Bekliyor',
            self::STATUS_REVISION_REQUESTED => 'Revize İstendi',
            self::STATUS_APPROVED => 'Onaylandı',
            self::STATUS_PRODUCTION_READY => 'Üretime Hazır',
            self::STATUS_NOT_REQUIRED => 'Gerekli Değil',
            default => ucfirst(str_replace('_', ' ', (string) $this->status)),
        };
    }

    public function safeCustomerApprovalLabel(): string
    {
        return match ($this->customer_approval_status) {
            self::CUSTOMER_APPROVAL_NOT_REQUIRED => 'Onay Gerekmiyor',
            self::CUSTOMER_APPROVAL_WAITING => 'Onay Bekliyor',
            self::CUSTOMER_APPROVAL_APPROVED => 'Onaylandı',
            self::CUSTOMER_APPROVAL_REVISION_REQUESTED => 'Revize İstendi',
            self::CUSTOMER_APPROVAL_REJECTED => 'Reddedildi',
            default => ucfirst(str_replace('_', ' ', (string) $this->customer_approval_status)),
        };
    }

    public function canMarkProductionReady(): bool
    {
        if ($this->isRevisionRequested()) {
            return false;
        }

        return in_array($this->status, [
            self::STATUS_VISUAL_UPLOADED,
            self::STATUS_APPROVED,
            self::STATUS_PRODUCTION_READY,
        ], true);
    }
}
