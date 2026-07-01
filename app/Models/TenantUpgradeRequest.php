<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantUpgradeRequest extends Model
{
    use HasFactory;

    public const TYPE_PACKAGE_UPGRADE = 'package_upgrade';
    public const TYPE_MODULE_ADDON = 'module_addon';
    public const TYPE_FEATURE_ADDON = 'feature_addon';
    public const TYPE_LIMIT_INCREASE = 'limit_increase';
    public const TYPE_SUPPLIER_ACCESS = 'supplier_access';
    public const TYPE_SERVICE_REQUEST = 'service_request';

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_account_id',
        'requested_by_user_id',
        'request_type',
        'status',
        'current_package_key',
        'requested_package_key',
        'requested_module_key',
        'requested_feature_key',
        'requested_limit_key',
        'current_limit_value',
        'requested_limit_value',
        'requested_supplier_id',
        'requested_supplier_key',
        'requested_service_key',
        'requested_note',
        'admin_note',
        'reviewed_by_user_id',
        'reviewed_at',
        'applied_by_user_id',
        'applied_at',
        'source_type',
        'source_id',
        'meta_json',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
        'meta_json' => 'array',
    ];

    public function tenantAccount(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isInReview(): bool
    {
        return $this->status === self::STATUS_IN_REVIEW;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_IN_REVIEW, self::STATUS_APPROVED], true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_REJECTED, self::STATUS_APPLIED, self::STATUS_CANCELLED], true);
    }

    public function canBeReviewed(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_IN_REVIEW], true);
    }

    public function canBeApplied(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPackageUpgrade(): bool
    {
        return $this->request_type === self::TYPE_PACKAGE_UPGRADE;
    }

    public function isModuleAddon(): bool
    {
        return $this->request_type === self::TYPE_MODULE_ADDON;
    }

    public function isFeatureAddon(): bool
    {
        return $this->request_type === self::TYPE_FEATURE_ADDON;
    }

    public function isLimitIncrease(): bool
    {
        return $this->request_type === self::TYPE_LIMIT_INCREASE;
    }

    public function isSupplierAccess(): bool
    {
        return $this->request_type === self::TYPE_SUPPLIER_ACCESS;
    }

    public function isServiceRequest(): bool
    {
        return $this->request_type === self::TYPE_SERVICE_REQUEST;
    }

    public function requestTypeLabel(): string
    {
        return self::requestTypeOptions()[$this->request_type] ?? $this->request_type;
    }

    public function statusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? $this->status;
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'amber',
            self::STATUS_IN_REVIEW => 'blue',
            self::STATUS_APPROVED => 'green',
            self::STATUS_REJECTED => 'gray',
            self::STATUS_APPLIED => 'green',
            self::STATUS_CANCELLED => 'gray',
            default => 'gray',
        };
    }

    public static function requestTypeOptions(): array
    {
        return [
            self::TYPE_PACKAGE_UPGRADE => 'Paket Yükseltme',
            self::TYPE_MODULE_ADDON => 'Ek Modül Talebi',
            self::TYPE_FEATURE_ADDON => 'Ek Özellik Talebi',
            self::TYPE_LIMIT_INCREASE => 'Limit Artırma',
            self::TYPE_SUPPLIER_ACCESS => 'Tedarikçi Erişimi',
            self::TYPE_SERVICE_REQUEST => 'Ek Hizmet Talebi',
        ];
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_PENDING => 'Bekliyor',
            self::STATUS_IN_REVIEW => 'İncelemede',
            self::STATUS_APPROVED => 'Onaylandı',
            self::STATUS_REJECTED => 'Reddedildi',
            self::STATUS_APPLIED => 'Uygulandı',
            self::STATUS_CANCELLED => 'İptal Edildi',
        ];
    }
}
