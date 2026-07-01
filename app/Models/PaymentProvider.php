<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentProvider extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PASSIVE = 'passive';

    protected $fillable = [
        'provider_key',
        'driver_key',
        'display_name',
        'status',
        'checkout_mode',
        'supports_shared_saas_payments',
        'supports_tenant_module',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'supports_shared_saas_payments' => 'boolean',
        'supports_tenant_module' => 'boolean',
    ];

    public function credentials(): HasMany
    {
        return $this->hasMany(PaymentGatewayCredential::class);
    }

    public function sharedCredential(): HasOne
    {
        return $this->hasOne(PaymentGatewayCredential::class)
            ->where('scope_type', PaymentGatewayCredential::SCOPE_SUPER_ADMIN_SHARED)
            ->whereNull('tenant_account_id');
    }

    public function checkoutSessions(): HasMany
    {
        return $this->hasMany(PaymentCheckoutSession::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function webhookLogs(): HasMany
    {
        return $this->hasMany(PaymentWebhookLog::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'Aktif',
            self::STATUS_PASSIVE => 'Pasif',
            default => 'Taslak',
        };
    }
}
