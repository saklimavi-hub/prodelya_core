<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'website',
        'contact_email',
        'contact_phone',
        'status',
        'config',
    ];

    protected $casts = [
        'config' => 'array',
        'status' => 'string',
    ];

    /**
     * Get the sources for this supplier
     */
    public function sources(): HasMany
    {
        return $this->hasMany(SupplierSource::class);
    }

    /**
     * Get the raw products for this supplier
     */
    public function rawProducts(): HasMany
    {
        return $this->hasMany(SupplierProductRaw::class);
    }

    public function rawVariants(): HasMany
    {
        return $this->hasMany(SupplierProductVariantRaw::class);
    }

    /**
     * Get the standard products for this supplier
     */
    public function standardProducts(): HasMany
    {
        return $this->hasMany(StandardProduct::class);
    }

    /**
     * Get the field mappings for this supplier
     */
    public function fieldMappings(): HasMany
    {
        return $this->hasMany(SupplierFieldMapping::class);
    }

    /**
     * Get the category mappings for this supplier
     */
    public function categoryMappings(): HasMany
    {
        return $this->hasMany(SupplierCategoryMapping::class);
    }

    /**
     * Get the sync logs for this supplier
     */
    public function syncLogs(): HasMany
    {
        return $this->hasMany(FeedSyncLog::class);
    }

    /**
     * Get the tenants that have access to this supplier
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(TenantAccount::class, 'tenant_supplier_access')
            ->withPivot([
                'is_active',
                'granted_at',
                'expires_at',
                'can_view_products',
                'can_request_purchase',
                'can_use_in_quotes',
                'price_multiplier',
                'safe_stock_quantity',
                'visible_in_catalog',
                'export_allowed',
                'meta',
            ])
            ->withTimestamps();
    }

    /**
     * Scope to get active suppliers
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get inactive suppliers
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Scope to get suspended suppliers
     */
    public function scopeSuspended($query)
    {
        return $query->where('status', 'suspended');
    }

    /**
     * Check if supplier is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if supplier is suspended
     */
    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }
}
