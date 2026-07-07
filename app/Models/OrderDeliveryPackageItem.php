<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDeliveryPackageItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'order_delivery_package_id',
        'order_id',
        'order_item_id',
        'quantity',
        'item_name_snapshot',
        'item_sku_snapshot',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(OrderDeliveryPackage::class, 'order_delivery_package_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
