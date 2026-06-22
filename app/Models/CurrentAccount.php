<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CurrentAccount extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PASSIVE = 'passive';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'tenant_account_id',
        'account_code',
        'display_name',
        'legal_name',
        'short_name',
        'tax_office',
        'tax_number',
        'tc_no',
        'email',
        'phone',
        'mobile',
        'website',
        'default_currency',
        'payment_terms_days',
        'risk_limit',
        'risk_status',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'payment_terms_days' => 'integer',
        'risk_limit' => 'decimal:2',
        'status' => 'string',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function account(): BelongsTo
    {
        return $this->tenant();
    }

    public function roles(): HasMany
    {
        return $this->hasMany(CurrentAccountRole::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(CurrentAccountLink::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CurrentAccountTransaction::class);
    }

    public function companyLinks(): HasMany
    {
        return $this->links()->where('link_type', CurrentAccountLink::LINK_COMPANY);
    }

    public function primaryCompanyLink(): HasOne
    {
        return $this->hasOne(CurrentAccountLink::class)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('is_primary', true);
    }

    public function supplierLinks(): HasMany
    {
        return $this->links()->where('link_type', CurrentAccountLink::LINK_SUPPLIER);
    }

    public function primarySupplierLink(): HasOne
    {
        return $this->hasOne(CurrentAccountLink::class)
            ->where('link_type', CurrentAccountLink::LINK_SUPPLIER)
            ->where('is_primary', true);
    }

    public function customerRole(): HasOne
    {
        return $this->hasOne(CurrentAccountRole::class)->where('role', CurrentAccountRole::ROLE_CUSTOMER);
    }

    public function supplierRole(): HasOne
    {
        return $this->hasOne(CurrentAccountRole::class)->where('role', CurrentAccountRole::ROLE_SUPPLIER);
    }

    public function subcontractorRole(): HasOne
    {
        return $this->hasOne(CurrentAccountRole::class)->where('role', CurrentAccountRole::ROLE_SUBCONTRACTOR);
    }

    public function carrierRole(): HasOne
    {
        return $this->hasOne(CurrentAccountRole::class)->where('role', CurrentAccountRole::ROLE_CARRIER);
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('role', $role)->exists();
    }

    public function isCustomer(): bool
    {
        return $this->hasRole(CurrentAccountRole::ROLE_CUSTOMER);
    }

    public function isSupplier(): bool
    {
        return $this->hasRole(CurrentAccountRole::ROLE_SUPPLIER);
    }

    public function isSubcontractor(): bool
    {
        return $this->hasRole(CurrentAccountRole::ROLE_SUBCONTRACTOR);
    }

    public function isCarrier(): bool
    {
        return $this->hasRole(CurrentAccountRole::ROLE_CARRIER);
    }

    public function safeDisplayName(): string
    {
        return trim((string) ($this->display_name ?: $this->legal_name ?: $this->short_name ?: ('Cari #' . $this->id)));
    }

    public function safeStatusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? ucfirst(str_replace('_', ' ', (string) $this->status));
    }

    public static function roleLabels(): array
    {
        return CurrentAccountRole::roleLabels();
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_ACTIVE => 'Aktif',
            self::STATUS_PASSIVE => 'Pasif',
            self::STATUS_BLOCKED => 'Bloklu',
            self::STATUS_ARCHIVED => 'Arşiv',
        ];
    }

    public function safeRiskStatusLabel(): string
    {
        return self::riskStatusLabels()[$this->risk_status] ?? '-';
    }

    public static function riskStatusLabels(): array
    {
        return [
            'low' => 'Düşük',
            'medium' => 'Orta',
            'high' => 'Yüksek',
            'critical' => 'Kritik',
        ];
    }
}
