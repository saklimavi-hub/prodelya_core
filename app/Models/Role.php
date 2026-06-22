<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'name',
        'key',
        'description',
        'permissions',
        'is_system',
        'is_active',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    // TODO: Add permission checking methods
    // TODO: Add scope methods for active roles
    // TODO: Add validation for role keys against allowed permissions
    
    /**
     * Get the tenant that owns this role
     */
    public function tenant()
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the users that have this role
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot('tenant_account_id')
            ->withTimestamps();
    }

    /**
     * Scope to get only active roles
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get system roles
     */
    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope to get tenant-specific roles
     */
    public function scopeTenant($query, $tenantId)
    {
        return $query->where('tenant_account_id', $tenantId);
    }

    /**
     * Check if role has a specific permission
     */
    public function hasPermission($permission)
    {
        $permissions = $this->normalizedPermissions();

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    /**
     * Check if role has any of the given permissions
     */
    public function hasAnyPermission(array $permissions)
    {
        $normalizedPermissions = $this->normalizedPermissions();

        return in_array('*', $normalizedPermissions, true)
            || !empty(array_intersect($permissions, $normalizedPermissions));
    }

    /**
     * Check if role has all of the given permissions
     */
    public function hasAllPermissions(array $permissions)
    {
        $normalizedPermissions = $this->normalizedPermissions();

        return in_array('*', $normalizedPermissions, true)
            || empty(array_diff($permissions, $normalizedPermissions));
    }

    private function normalizedPermissions(): array
    {
        $permissions = $this->permissions ?? [];

        if (!is_array($permissions)) {
            return [];
        }

        $flattened = [];

        array_walk_recursive($permissions, function ($value) use (&$flattened) {
            if (is_string($value)) {
                $flattened[] = $value;
            }
        });

        return array_values(array_unique($flattened));
    }
}
