<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemWorkFormActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'work_form_id',
        'order_id',
        'order_item_id',
        'action_type',
        'old_status',
        'new_status',
        'note',
        'attachment_id',
        'visibility',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function workForm(): BelongsTo
    {
        return $this->belongsTo(OrderItemWorkForm::class, 'work_form_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(OrderItemWorkFormAttachment::class, 'attachment_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
