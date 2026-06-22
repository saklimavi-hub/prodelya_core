<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrentAccountRole extends Model
{
    use HasFactory;

    public const ROLE_CUSTOMER = 'customer';
    public const ROLE_SUPPLIER = 'supplier';
    public const ROLE_SUBCONTRACTOR = 'subcontractor';
    public const ROLE_CARRIER = 'carrier';
    public const ROLE_SERVICE_PROVIDER = 'service_provider';
    public const ROLE_OTHER = 'other';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PASSIVE = 'passive';

    protected $fillable = [
        'tenant_account_id',
        'current_account_id',
        'role',
        'is_primary',
        'status',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
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

    public function currentAccount(): BelongsTo
    {
        return $this->belongsTo(CurrentAccount::class);
    }

    public function safeRoleLabel(): string
    {
        return self::roleLabels()[$this->role] ?? ucfirst(str_replace('_', ' ', (string) $this->role));
    }

    public static function roleLabels(): array
    {
        return [
            self::ROLE_CUSTOMER => 'Müşteri',
            self::ROLE_SUPPLIER => 'Tedarikçi',
            self::ROLE_SUBCONTRACTOR => 'Fasoncu',
            self::ROLE_CARRIER => 'Kargo / Kurye',
            self::ROLE_SERVICE_PROVIDER => 'Hizmet Sağlayıcı',
            self::ROLE_OTHER => 'Diğer',
        ];
    }
}
