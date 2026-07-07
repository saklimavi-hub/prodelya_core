<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderDeliveryPackage extends Model
{
    use HasFactory;

    public const STATUS_PLANNED = 'planned';
    public const STATUS_LABEL_READY = 'label_ready';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_BOX = 'box';
    public const TYPE_BAG = 'bag';
    public const TYPE_PALLET = 'pallet';
    public const TYPE_OTHER = 'other';

    protected $fillable = [
        'tenant_account_id',
        'order_id',
        'package_no',
        'package_label',
        'package_type',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderDeliveryPackageItem::class, 'order_delivery_package_id');
    }

    public static function packageTypeLabels(): array
    {
        return [
            self::TYPE_BOX => 'Koli',
            self::TYPE_BAG => 'Çuval / Torba',
            self::TYPE_PALLET => 'Palet',
            self::TYPE_OTHER => 'Diğer',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_PLANNED => 'Planlandı',
            self::STATUS_LABEL_READY => 'Etiket Hazır',
            self::STATUS_DELIVERED => 'Teslim Edildi',
            self::STATUS_CANCELLED => 'İptal',
        ];
    }
}
