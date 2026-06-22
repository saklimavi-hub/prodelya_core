<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItemWorkForm extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'order_id',
        'order_item_id',
        'source_quote_id',
        'source_quote_number',
        'work_form_number',
        'item_sequence',
        'status',
        'version',
        'public_tracking_token',
        'order_snapshot',
        'customer_snapshot',
        'product_snapshot',
        'print_snapshot',
        'graphic_snapshot',
        'production_snapshot',
        'delivery_snapshot',
        'procurement_snapshot',
        'notes',
        'last_rendered_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'order_snapshot' => 'array',
        'customer_snapshot' => 'array',
        'product_snapshot' => 'array',
        'print_snapshot' => 'array',
        'graphic_snapshot' => 'array',
        'production_snapshot' => 'array',
        'delivery_snapshot' => 'array',
        'procurement_snapshot' => 'array',
        'last_rendered_at' => 'datetime',
        'item_sequence' => 'integer',
        'version' => 'integer',
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

    public function sourceQuote(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'source_quote_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OrderItemWorkFormAttachment::class, 'work_form_id');
    }

    public function graphicAttachments(): HasMany
    {
        return $this->attachments()->whereIn('attachment_type', ['graphic_visual', 'customer_approval']);
    }

    public function productionPhotos(): HasMany
    {
        return $this->attachments()->where('attachment_type', 'production_photo');
    }

    public function deliveryAttachments(): HasMany
    {
        return $this->attachments()->whereIn('attachment_type', ['delivery_photo', 'delivery_document']);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(OrderItemWorkFormActivityLog::class, 'work_form_id');
    }

    public function workFolders(): HasMany
    {
        return $this->hasMany(OrderItemWorkFolder::class, 'work_form_id');
    }

    public function procurement(): HasOne
    {
        return $this->hasOne(OrderItemProcurement::class, 'work_form_id');
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(OrderItemWorkFormDelivery::class, 'work_form_id');
    }

    public function printProductions(): HasMany
    {
        return $this->hasMany(OrderItemPrintProduction::class, 'work_form_id');
    }

    public function printGraphics(): HasMany
    {
        return $this->hasMany(OrderItemPrintGraphic::class, 'order_item_work_form_id');
    }

    public function graphicApprovalRequests(): HasMany
    {
        return $this->hasMany(GraphicApprovalRequest::class, 'work_form_id');
    }

    public function systemWorkFolder(): HasOne
    {
        return $this->hasOne(OrderItemWorkFolder::class, 'work_form_id')->where('folder_type', 'system');
    }

    public function publicActivityLogs(): HasMany
    {
        return $this->activityLogs()->where('visibility', 'customer_visible');
    }

    public function internalActivityLogs(): HasMany
    {
        return $this->activityLogs()->where('visibility', 'internal');
    }
}
