<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPriceSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'standard_product_id',
        'tenant_catalog_product_id',
        'snapshot_type',
        'purchase_price',
        'purchase_currency',
        'selling_price',
        'selling_currency',
        'reference_type',
        'reference_id',
        'reference_date',
        'created_by',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'reference_date' => 'datetime',
    ];

    /**
     * Get the tenant that owns the price snapshot
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the standard product that owns the price snapshot
     */
    public function standardProduct(): BelongsTo
    {
        return $this->belongsTo(StandardProduct::class);
    }

    /**
     * Get the catalog product that owns the price snapshot
     */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(TenantCatalogProduct::class, 'tenant_catalog_product_id');
    }

    /**
     * Scope to get quote snapshots
     */
    public function scopeQuote($query)
    {
        return $query->where('snapshot_type', 'quote');
    }

    /**
     * Scope to get order snapshots
     */
    public function scopeOrder($query)
    {
        return $query->where('snapshot_type', 'order');
    }

    /**
     * Scope to get sync snapshots
     */
    public function scopeSync($query)
    {
        return $query->where('snapshot_type', 'sync');
    }

    /**
     * Scope to get snapshots by type
     */
    public function scopeByType($query, $type)
    {
        return $query->where('snapshot_type', $type);
    }

    /**
     * Scope to get snapshots by reference
     */
    public function scopeByReference($query, $type, $id)
    {
        return $query->where('reference_type', $type)
                    ->where('reference_id', $id);
    }

    /**
     * Scope to get recent snapshots
     */
    public function scopeRecent($query, $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Check if snapshot is for quote
     */
    public function isQuote(): bool
    {
        return $this->snapshot_type === 'quote';
    }

    /**
     * Check if snapshot is for order
     */
    public function isOrder(): bool
    {
        return $this->snapshot_type === 'order';
    }

    /**
     * Check if snapshot is for sync
     */
    public function isSync(): bool
    {
        return $this->snapshot_type === 'sync';
    }

    /**
     * Get the snapshot type display name
     */
    public function getSnapshotTypeDisplayName(): string
    {
        return match($this->snapshot_type) {
            'quote' => 'Teklif',
            'order' => 'Sipariş',
            'sync' => 'Senkronizasyon',
            'manual' => 'Manuel',
            default => ucfirst($this->snapshot_type),
        };
    }

    /**
     * Get the formatted purchase price
     */
    public function getFormattedPurchasePrice(): string
    {
        if (!$this->purchase_price) {
            return 'Fiyat Yok';
        }

        $currency = $this->purchase_currency ?? 'TL';
        return number_format($this->purchase_price, 2, ',', '.') . ' ' . $currency;
    }

    /**
     * Get the formatted selling price
     */
    public function getFormattedSellingPrice(): string
    {
        if (!$this->selling_price) {
            return 'Fiyat Yok';
        }

        $currency = $this->selling_currency ?? 'TL';
        return number_format($this->selling_price, 2, ',', '.') . ' ' . $currency;
    }

    /**
     * Get the reference display name
     */
    public function getReferenceDisplayName(): string
    {
        if (!$this->reference_type || !$this->reference_id) {
            return 'Genel';
        }

        return match($this->reference_type) {
            'quote' => 'Teklif #' . $this->reference_id,
            'order' => 'Sipariş #' . $this->reference_id,
            'sync' => 'Senkronizasyon #' . $this->reference_id,
            default => ucfirst($this->reference_type) . ' #' . $this->reference_id,
        };
    }

    /**
     * Get the price change percentage
     */
    public function getPriceChangePercentage(): ?float
    {
        if (!$this->purchase_price) {
            return null;
        }

        // Get the previous snapshot for comparison
        $previousSnapshot = static::where('tenant_catalog_product_id', $this->tenant_catalog_product_id)
                                  ->where('id', '<', $this->id)
                                  ->where('purchase_price', '>', 0)
                                  ->orderBy('id', 'desc')
                                  ->first();

        if (!$previousSnapshot) {
            return null;
        }

        $change = (($this->purchase_price - $previousSnapshot->purchase_price) / $previousSnapshot->purchase_price) * 100;
        return round($change, 2);
    }

    /**
     * Get the price change badge
     */
    public function getPriceChangeBadge(): array
    {
        $change = $this->getPriceChangePercentage();

        if ($change === null) {
            return ['color' => 'gray', 'text' => 'İlk Kayıt'];
        }

        if ($change > 0) {
            return ['color' => 'red', 'text' => '+' . $change . '%'];
        } elseif ($change < 0) {
            return ['color' => 'green', 'text' => $change . '%'];
        } else {
            return ['color' => 'gray', 'text' => 'Değişmedi'];
        }
    }
}
