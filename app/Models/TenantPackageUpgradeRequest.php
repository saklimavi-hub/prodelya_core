<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantPackageUpgradeRequest extends Model
{
    use HasFactory;

    public const STATUS_NEW = 'new';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'tenant_account_id',
        'requested_by_user_id',
        'current_package_key',
        'requested_package_key',
        'status',
        'request_note',
        'admin_note',
        'approved_by_user_id',
        'approved_at',
        'applied_at',
        'meta_json',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'applied_at' => 'datetime',
        'meta_json' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function requestedPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'requested_package_key', 'key');
    }

    public function currentPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'current_package_key', 'key');
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_NEW => 'Yeni',
            self::STATUS_APPROVED => 'Onaylandı',
            self::STATUS_REJECTED => 'Reddedildi',
            self::STATUS_COMPLETED => 'Tamamlandı',
        ];
    }
}
