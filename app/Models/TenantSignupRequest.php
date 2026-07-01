<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSignupRequest extends Model
{
    use HasFactory;

    public const TYPE_TRIAL = 'trial';
    public const TYPE_DEMO = 'demo';

    public const STATUS_NEW = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'request_type',
        'company_name',
        'contact_name',
        'phone',
        'email',
        'city',
        'sector',
        'requested_package_id',
        'requested_package_key',
        'requested_modules_json',
        'expected_user_count',
        'demo_topic',
        'note',
        'status',
        'source',
        'converted_tenant_account_id',
        'meta_json',
    ];

    protected $casts = [
        'requested_modules_json' => 'array',
        'meta_json' => 'array',
        'expected_user_count' => 'integer',
    ];

    public function requestedPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'requested_package_id');
    }

    public function convertedTenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'converted_tenant_account_id');
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_NEW => 'Yeni',
            self::STATUS_CONTACTED => 'İletişime Geçildi',
            self::STATUS_CONVERTED => 'Abone Firma’ya Dönüştü',
            self::STATUS_REJECTED => 'Reddedildi',
            self::STATUS_ARCHIVED => 'Arşivlendi',
        ];
    }

    public static function typeOptions(): array
    {
        return [
            self::TYPE_TRIAL => '1 Ay Ücretsiz Dene',
            self::TYPE_DEMO => 'Demo Talebi',
        ];
    }
}
