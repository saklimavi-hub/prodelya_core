<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantModule extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'module_key',
        'feature_key',
        'is_enabled',
        'limit_value',
        'meta',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'limit_value' => 'integer',
        'meta' => 'array',
    ];

    // TODO: Add validation for module_key and feature_key against allowed modules
    // TODO: Add scope methods for enabled modules
    // TODO: Add methods to check feature access within modules
    
    /**
     * Get the tenant that owns this module
     */
    public function tenant()
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Scope to get only enabled modules
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope to get modules by key
     */
    public function scopeByKey($query, $moduleKey)
    {
        return $query->where('module_key', $moduleKey);
    }

    /**
     * Check if a specific feature is enabled for this module
     */
    public function hasFeature($featureKey)
    {
        return $this->feature_key === $featureKey && $this->is_enabled;
    }

    /**
     * Check if the tenant has reached the limit for this module
     */
    public function hasReachedLimit($currentCount = 0)
    {
        if ($this->limit_value === null) {
            return false; // No limit set
        }
        
        return $currentCount >= $this->limit_value;
    }
}
