<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentGatewayCredential extends Model
{
    use HasFactory;

    public const SCOPE_SUPER_ADMIN_SHARED = 'super_admin_shared';
    public const SCOPE_TENANT_MODULE = 'tenant_module';

    protected $fillable = [
        'payment_provider_id',
        'tenant_account_id',
        'scope_type',
        'is_active',
        'credentials_json',
        'settings_json',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'credentials_json' => 'array',
        'settings_json' => 'array',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'payment_provider_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeLabel(): string
    {
        return $this->scope_type === self::SCOPE_TENANT_MODULE
            ? 'Tenant Modülü'
            : 'Super Admin Ortak';
    }
}
