<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemWorkFolder extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'order_id',
        'order_item_id',
        'work_form_id',
        'folder_type',
        'storage_driver',
        'root_key',
        'relative_path',
        'display_path',
        'physical_path',
        'status',
        'last_checked_at',
        'error_message',
        'created_by',
    ];

    protected $casts = [
        'last_checked_at' => 'datetime',
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

    public function workForm(): BelongsTo
    {
        return $this->belongsTo(OrderItemWorkForm::class, 'work_form_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCreated(): bool
    {
        return $this->status === 'created';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function displayPath(): string
    {
        return (string) $this->display_path;
    }

    public function safeStatusLabel(): string
    {
        return match ($this->status) {
            'created' => 'Hazır',
            'pending' => 'Bekliyor',
            'failed' => 'Hata',
            'disabled' => 'Pasif',
            default => ucfirst((string) $this->status),
        };
    }
}
