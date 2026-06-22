<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class TenantSupplierAccess extends Model
{
    use HasFactory;

    protected $table = 'tenant_supplier_access';

    protected $fillable = [
        'tenant_account_id',
        'supplier_id',
        'is_active',
        'access_settings',
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
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'access_settings' => 'array',
        'granted_at' => 'datetime',
        'expires_at' => 'datetime',
        'can_view_products' => 'boolean',
        'can_request_purchase' => 'boolean',
        'can_use_in_quotes' => 'boolean',
        'price_multiplier' => 'decimal:2',
        'safe_stock_quantity' => 'integer',
        'visible_in_catalog' => 'boolean',
        'export_allowed' => 'boolean',
        'meta' => 'array',
    ];

    /**
     * Get the tenant that owns the access
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the supplier that is being accessed
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Scope to get active access
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get pending access
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * Check if access is granted
     */
    public function isGranted(): bool
    {
        return $this->is_active;
    }

    /**
     * Check whether the record should currently be treated as usable.
     */
    public function isCurrentlyAccessible(): bool
    {
        return $this->is_active && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Access status label for UI usage.
     */
    public function getStatusLabel(): string
    {
        return $this->isCurrentlyAccessible() ? 'Aktif' : 'Pasif';
    }
}
