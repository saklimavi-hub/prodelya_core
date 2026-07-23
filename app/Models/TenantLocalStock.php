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
        'tenant_catalog_product_variant_id',
        'stock_scope',
        'legacy_assignment_status',
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
        'stock_scope' => 'string',
        'legacy_assignment_status' => 'string',
        'reorder_level' => 'decimal:4',
        'max_stock' => 'decimal:4',
        'last_counted_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(TenantCatalogProduct::class, 'tenant_catalog_product_id');
    }

    public function catalogVariant(): BelongsTo
    {
        return $this->belongsTo(TenantCatalogProductVariant::class, 'tenant_catalog_product_variant_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(TenantStockReservation::class, 'tenant_local_stock_id');
    }

    public function scopeLowStock($query)
    {
        return $query->whereColumn('quantity_on_hand', '<=', 'reorder_level');
    }

    public function scopeNeedsReorder($query)
    {
        return $query->whereColumn('quantity_on_hand', '<=', 'reorder_level');
    }

    public function scopeByWarehouse($query, $warehouseCode)
    {
        return $query->where('warehouse_code', $warehouseCode);
    }

    public function scopeAvailable($query)
    {
        return $query->where('quantity_available', '>', 0);
    }

    public function isLowStock(): bool
    {
        return (float) $this->quantity_on_hand <= (float) ($this->reorder_level ?? 0);
    }

    public function needsReorder(): bool
    {
        return (float) $this->quantity_on_hand <= (float) ($this->reorder_level ?? 0);
    }

    public function isAvailable(): bool
    {
        return (float) $this->quantity_available > 0;
    }

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

    public function getFormattedStockQuantity(): string
    {
        return number_format((float) $this->quantity_on_hand, 0, '.', '.') . ' Adet';
    }

    public function getFormattedAvailableQuantity(): string
    {
        return number_format((float) $this->quantity_available, 0, '.', '.') . ' Adet';
    }

    public function getFormattedReservedQuantity(): string
    {
        return number_format((float) $this->quantity_reserved, 0, '.', '.') . ' Adet';
    }

    public function updateAvailableQuantity(): void
    {
        $this->quantity_available = max((float) $this->quantity_on_hand - (float) $this->quantity_reserved, 0);
        $this->save();
    }
}
