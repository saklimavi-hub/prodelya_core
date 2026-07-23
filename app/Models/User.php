<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'phone', 'password', 'is_platform_admin', 'last_login_at', 'last_login_ip'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    // TODO: Add tenant-aware role relationships
    // TODO: Add permission checking methods
    // TODO: Add tenant context methods

    /**
     * Get the user's roles across all tenants
     */
    public function userRoles()
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * Get the user's roles for a specific tenant
     */
    public function rolesForTenant($tenantId)
    {
        return $this->userRoles()
            ->where('tenant_account_id', $tenantId)
            ->with('role')
            ->get()
            ->pluck('role');
    }

    /**
     * Check if user has a specific role in a tenant
     */
    public function hasRoleInTenant($roleKey, $tenantId)
    {
        return $this->userRoles()
            ->where('tenant_account_id', $tenantId)
            ->whereHas('role', function ($query) use ($roleKey) {
                $query->where('key', $roleKey)->active();
            })
            ->exists();
    }

    /**
     * Check if user has a specific permission in a tenant
     */
    public function hasPermissionInTenant($permission, $tenantId)
    {
        $roles = $this->rolesForTenant($tenantId);

        foreach ($roles as $role) {
            if ($role->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has any of the given permissions in a tenant
     */
    public function hasAnyPermissionInTenant(array $permissions, $tenantId)
    {
        $roles = $this->rolesForTenant($tenantId);

        foreach ($roles as $role) {
            if ($role->hasAnyPermission($permissions)) {
                return true;
            }
        }

        return false;
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) ($this->is_platform_admin ?? false);
    }

    public function belongsToTenant(TenantAccount|int $tenant): bool
    {
        $tenantId = $tenant instanceof TenantAccount ? (int) $tenant->id : (int) $tenant;

        if ($tenantId <= 0) {
            return false;
        }

        return $this->userRoles()
            ->where('tenant_account_id', $tenantId)
            ->whereHas('role', fn ($query) => $query->active())
            ->exists();
    }

    public function activeTenantRoles(): EloquentCollection
    {
        return $this->userRoles()
            ->with(['tenant', 'role'])
            ->whereHas('role', fn ($query) => $query->active())
            ->orderBy('tenant_account_id')
            ->get();
    }

    public function preferredTenant(): ?TenantAccount
    {
        /** @var UserRole|null $assignment */
        $assignment = $this->activeTenantRoles()
            ->first(function (UserRole $userRole): bool {
                $status = strtolower(trim((string) ($userRole->tenant?->status ?? '')));

                return $userRole->tenant instanceof TenantAccount
                    && !in_array($status, ['inactive', 'suspended', 'passive'], true);
            });

        return $assignment?->tenant;
    }

    /**
     * Check if user can view sensitive financial data in a tenant
     */

    public function canViewFinancialData($tenantId)
    {
        $financialPermissions = [
            'view_order_finance_summary',
            'view_sales_prices',
            'view_quote_totals',
            'view_profit_margin',
            'view_customer_balance',
            'view_payment_details',
            'view_actual_costs',
            'view_current_account_transactions',
            'manage_current_account_transactions',
            'cancel_current_account_transactions',
        ];

        return $this->hasAnyPermissionInTenant($financialPermissions, $tenantId);
    }
}
