<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserRole extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'role_id',
        'tenant_account_id',
    ];

    /**
     * Any role assignment change must invalidate User::rolesForTenant()'s
     * request-scoped cache - see that method's docblock for why this can't
     * rely on the HTTP request boundary alone.
     */
    protected static function booted(): void
    {
        static::saved(fn () => User::forgetRolesForTenantCache());
        static::deleted(fn () => User::forgetRolesForTenantCache());
    }

    /**
     * Get the user that owns the role assignment
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the role that is assigned
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Get the tenant for this role assignment
     */
    public function tenant()
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }
}
