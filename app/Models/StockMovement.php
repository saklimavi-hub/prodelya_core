<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'standard_product_id',
        'tenant_catalog_product_id',
        'tenant_local_stock_id',
        'order_id',
        'movement_type',
        'quantity',
        'reference_type',
        'reference_id',
        'reference_document',
        'reason',
        'unit_cost',
        'currency',
        'warehouse_code',
        'location_code',
        'notes',
        'moved_by',
        'moved_at',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:2',
        'moved_at' => 'datetime',
    ];

    /**
     * Get the tenant that owns the stock movement
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the standard product that owns the stock movement
     */
    public function standardProduct(): BelongsTo
    {
        return $this->belongsTo(StandardProduct::class);
    }

    /**
     * Get the catalog product that owns the stock movement
     */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(TenantCatalogProduct::class, 'tenant_catalog_product_id');
    }

    /**
     * Get the local stock that owns the stock movement
     */
    public function localStock(): BelongsTo
    {
        return $this->belongsTo(TenantLocalStock::class, 'tenant_local_stock_id');
    }

    /**
     * Scope to get inbound movements
     */
    public function scopeInbound($query)
    {
        return $query->where('movement_type', 'inbound');
    }

    /**
     * Scope to get outbound movements
     */
    public function scopeOutbound($query)
    {
        return $query->where('movement_type', 'outbound');
    }

    /**
     * Scope to get adjustment movements
     */
    public function scopeAdjustment($query)
    {
        return $query->where('movement_type', 'adjustment');
    }

    /**
     * Scope to get movements by type
     */
    public function scopeByType($query, $type)
    {
        return $query->where('movement_type', $type);
    }

    /**
     * Scope to get movements by reference
     */
    public function scopeByReference($query, $type, $id)
    {
        return $query->where('reference_type', $type)
                    ->where('reference_id', $id);
    }

    /**
     * Scope to get recent movements
     */
    public function scopeRecent($query, $days = 30)
    {
        return $query->where('moved_at', '>=', now()->subDays($days));
    }

    /**
     * Check if movement is inbound
     */
    public function isInbound(): bool
    {
        return $this->movement_type === 'inbound';
    }

    /**
     * Check if movement is outbound
     */
    public function isOutbound(): bool
    {
        return $this->movement_type === 'outbound';
    }

    /**
     * Check if movement is adjustment
     */
    public function isAdjustment(): bool
    {
        return $this->movement_type === 'adjustment';
    }

    /**
     * Get the movement type display name
     */
    public function getMovementTypeDisplayName(): string
    {
        return match($this->movement_type) {
            'inbound' => 'Giriş',
            'outbound' => 'Çıkış',
            'adjustment' => 'Düzeltme',
            'transfer' => 'Transfer',
            'return' => 'İade',
            default => ucfirst($this->movement_type),
        };
    }

    /**
     * Get the movement type badge
     */
    public function getMovementTypeBadge(): array
    {
        return match($this->movement_type) {
            'inbound' => ['color' => 'green', 'text' => 'Giriş'],
            'outbound' => ['color' => 'red', 'text' => 'Çıkış'],
            'adjustment' => ['color' => 'yellow', 'text' => 'Düzeltme'],
            'transfer' => ['color' => 'blue', 'text' => 'Transfer'],
            'return' => ['color' => 'purple', 'text' => 'İade'],
            default => ['color' => 'gray', 'text' => ucfirst($this->movement_type)],
        };
    }

    /**
     * Get the formatted quantity with sign
     */
    public function getFormattedQuantity(): string
    {
        $sign = $this->isOutbound() ? '-' : '+';
        return $sign . abs($this->quantity);
    }

    /**
     * Get the reference display name
     */
    public function getReferenceDisplayName(): string
    {
        if (!$this->reference_type || !$this->reference_id) {
            return 'Manuel';
        }

        return match($this->reference_type) {
            'order' => 'Sipariş #' . $this->reference_id,
            'purchase' => 'Satın Alma #' . $this->reference_id,
            'return' => 'İade #' . $this->reference_id,
            'adjustment' => 'Düzeltme #' . $this->reference_id,
            default => ucfirst($this->reference_type) . ' #' . $this->reference_id,
        };
    }
}
