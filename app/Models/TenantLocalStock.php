<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantLocalStock extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'tenant_catalog_product_id',
        'warehouse_code',
        'location_code',
        'quantity_on_hand',
        'quantity_reserved',
        'quantity_available',
        'reorder_level',
        'max_stock',
        'last_counted_at',
        'notes',
    ];

    protected $casts = [
        'quantity_on_hand' => 'decimal:4',
        'quantity_reserved' => 'decimal:4',
        'quantity_available' => 'decimal:4',
        'reorder_level' => 'decimal:4',
        'max_stock' => 'decimal:4',
        'last_counted_at' => 'datetime',
    ];

    /**
     * Get the tenant that owns the local stock
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the catalog product that owns the local stock
     */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(TenantCatalogProduct::class, 'tenant_catalog_product_id');
    }

    /**
     * Get the stock movements for this local stock
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Scope to get stocks with low quantity
     */
    public function scopeLowStock($query)
    {
        return $query->whereColumn('quantity_on_hand', '<=', 'reorder_level');
    }

    /**
     * Scope to get stocks that need reorder
     */
    public function scopeNeedsReorder($query)
    {
        return $query->whereColumn('quantity_on_hand', '<=', 'reorder_level');
    }

    /**
     * Scope to get stocks by warehouse
     */
    public function scopeByWarehouse($query, $warehouseCode)
    {
        return $query->where('warehouse_code', $warehouseCode);
    }

    /**
     * Scope to get available stocks
     */
    public function scopeAvailable($query)
    {
        return $query->where('quantity_available', '>', 0);
    }

    /**
     * Check if stock is low
     */
    public function isLowStock(): bool
    {
        return (float) $this->quantity_on_hand <= (float) ($this->reorder_level ?? 0);
    }

    /**
     * Check if stock needs reorder
     */
    public function needsReorder(): bool
    {
        return (float) $this->quantity_on_hand <= (float) ($this->reorder_level ?? 0);
    }

    /**
     * Check if stock is available
     */
    public function isAvailable(): bool
    {
        return (float) $this->quantity_available > 0;
    }

    /**
     * Get the stock status badge
     */
    public function getStockStatusBadge(): array
    {
        if ((float) $this->quantity_on_hand <= 0) {
            return ['color' => 'red', 'text' => 'Stok Yok'];
        } elseif ((float) $this->quantity_on_hand <= (float) ($this->reorder_level ?? 0)) {
            return ['color' => 'yellow', 'text' => 'Düşük Stok'];
        } else {
            return ['color' => 'green', 'text' => 'Stok Var'];
        }
    }

    /**
     * Get the formatted stock quantity
     */
    public function getFormattedStockQuantity(): string
    {
        return number_format((float) $this->quantity_on_hand, 0, '.', '.') . ' Adet';
    }

    /**
     * Get the formatted available quantity
     */
    public function getFormattedAvailableQuantity(): string
    {
        return number_format((float) $this->quantity_available, 0, '.', '.') . ' Adet';
    }

    /**
     * Get the formatted reserved quantity
     */
    public function getFormattedReservedQuantity(): string
    {
        return number_format((float) $this->quantity_reserved, 0, '.', '.') . ' Adet';
    }

    /**
     * Update available quantity
     */
    public function updateAvailableQuantity(): void
    {
        $this->quantity_available = (float) $this->quantity_on_hand - (float) $this->quantity_reserved;
        $this->save();
    }
}
