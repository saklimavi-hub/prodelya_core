<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItemWorkFormAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'work_form_id',
        'order_id',
        'order_item_id',
        'order_item_print_graphic_id',
        'order_item_print_id',
        'attachment_type',
        'visibility',
        'file_path',
        'file_name',
        'mime_type',
        'disk',
        'uploaded_by',
        'note',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function workForm(): BelongsTo
    {
        return $this->belongsTo(OrderItemWorkForm::class, 'work_form_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function printGraphic(): BelongsTo
    {
        return $this->belongsTo(OrderItemPrintGraphic::class, 'order_item_print_graphic_id');
    }

    public function orderItemPrint(): BelongsTo
    {
        return $this->belongsTo(OrderItemPrint::class, 'order_item_print_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(OrderItemWorkFormActivityLog::class, 'attachment_id');
    }

    public function graphicApprovalRequests(): HasMany
    {
        return $this->hasMany(GraphicApprovalRequest::class, 'attachment_id');
    }

    public function isCustomerVisible(): bool
    {
        return $this->visibility === 'customer_visible';
    }

    public function isImage(): bool
    {
        $mimeType = strtolower(trim((string) $this->mime_type));

        if ($mimeType !== '') {
            return str_starts_with($mimeType, 'image/');
        }

        $extension = strtolower(pathinfo((string) ($this->file_name ?: $this->file_path), PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg'], true);
    }

    public function isDocument(): bool
    {
        return !$this->isImage();
    }
}
