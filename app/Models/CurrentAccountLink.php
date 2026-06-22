<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrentAccountLink extends Model
{
    use HasFactory;

    public const LINK_COMPANY = 'company';
    public const LINK_SUPPLIER = 'supplier';
    public const LINK_TENANT_SUPPLIER_ACCESS = 'tenant_supplier_access';
    public const LINK_PRODUCTION_COMPANY = 'production_company';
    public const LINK_CARRIER_TEXT = 'carrier_text';

    protected $fillable = [
        'tenant_account_id',
        'current_account_id',
        'link_type',
        'link_id',
        'is_primary',
        'meta_json',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'meta_json' => 'array',
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

    public function safeLinkLabel(): string
    {
        return self::linkLabels()[$this->link_type] ?? ucfirst(str_replace('_', ' ', (string) $this->link_type));
    }

    public static function linkLabels(): array
    {
        return [
            self::LINK_COMPANY => 'Firma',
            self::LINK_SUPPLIER => 'Global Tedarikçi',
            self::LINK_TENANT_SUPPLIER_ACCESS => 'Tenant Tedarikçi Erişimi',
            self::LINK_PRODUCTION_COMPANY => 'Üretim Firması',
            self::LINK_CARRIER_TEXT => 'Taşıyıcı Metni',
        ];
    }
}
