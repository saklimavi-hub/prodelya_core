<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class CustomerPortalUser extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PASSIVE = 'passive';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_INVITED = 'invited';

    protected $fillable = [
        'tenant_account_id',
        'company_id',
        'company_contact_id',
        'name',
        'email',
        'phone',
        'password',
        'status',
        'email_verified_at',
        'last_login_at',
        'last_login_ip',
        'invited_at',
        'invite_token',
        'invite_expires_at',
        'password_reset_token',
        'password_reset_expires_at',
        'password_set_at',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'invite_token',
        'password_reset_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'invited_at' => 'datetime',
            'invite_expires_at' => 'datetime',
            'password_reset_expires_at' => 'datetime',
            'password_set_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function tenantAccount(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->tenantAccount();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companyContact(): BelongsTo
    {
        return $this->belongsTo(CompanyContact::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isInvited(): bool
    {
        return $this->status === self::STATUS_INVITED;
    }

    public function hasPasswordSet(): bool
    {
        return filled($this->password) && $this->password_set_at !== null;
    }

    public function canAccessPortal(): bool
    {
        return $this->isActive() && (bool) $this->company?->portal_enabled;
    }

    public function belongsToTenant(TenantAccount|int $tenant): bool
    {
        $tenantId = $tenant instanceof TenantAccount ? $tenant->id : (int) $tenant;

        return (int) $this->tenant_account_id === $tenantId;
    }

    public function belongsToCompany(Company|int $company): bool
    {
        $companyId = $company instanceof Company ? $company->id : (int) $company;

        return (int) $this->company_id === $companyId;
    }

    public function safeDisplayName(): string
    {
        return trim((string) ($this->name ?: $this->email ?: 'Portal Kullanıcısı'));
    }

    public function safeStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'Aktif',
            self::STATUS_PASSIVE => 'Pasif',
            self::STATUS_SUSPENDED => 'Askıda',
            self::STATUS_INVITED => 'Davet Bekliyor',
            default => ucfirst((string) $this->status),
        };
    }

    public function scopeTenantId(): int
    {
        return (int) $this->tenant_account_id;
    }

    public function scopeCompanyId(): int
    {
        return (int) $this->company_id;
    }

    public function canSeeCompany(Company $company): bool
    {
        return $this->belongsToTenant($company->tenant_account_id)
            && $this->belongsToCompany($company);
    }

    public function canSeeOrder(Order $order): bool
    {
        return $this->belongsToTenant($order->tenant_account_id)
            && (int) $order->customer_company_id === (int) $this->company_id;
    }

    public function canSeeQuote(Order $quote): bool
    {
        return $quote->isQuote() && $this->canSeeOrder($quote);
    }

    public function canSeeWorkForm(OrderItemWorkForm $workForm): bool
    {
        if (! $this->belongsToTenant($workForm->tenant_account_id)) {
            return false;
        }

        $order = $workForm->relationLoaded('order') ? $workForm->order : $workForm->order()->first();

        return $order instanceof Order && $this->canSeeOrder($order);
    }
}
