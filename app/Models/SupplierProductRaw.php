<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupplierProductRaw extends Model
{
    use HasFactory;

    protected $table = 'supplier_products_raw';

    protected $fillable = [
        'tenant_account_id',
        'supplier_id',
        'supplier_source_id',
        'supplier_product_id',
        'supplier_product_code',
        'supplier_group_code',
        'product_name',
        'supplier_category_name',
        'standard_category_id',
        'standard_product_id',
        'stock_quantity',
        'purchase_price',
        'currency',
        'vat_rate',
        'image_url',
        'product_url',
        'detail_url',
        'color',
        'size',
        'measure',
        'description',
        'warning_flag',
        'raw_payload',
        'normalized_payload',
        'import_hash',
        'identity_hash',
        'content_hash',
        'price_hash',
        'stock_hash',
        'image_hash',
        'category_hash',
        'variant_structure_hash',
        'mapping_status',
        'warnings',
        'errors',
        'source_product_id',
        'source_sku',
        'source_name',
        'source_description',
        'source_category',
        'source_price',
        'source_currency',
        'source_stock',
        'source_attributes',
        'sync_status',
        'error_message',
        'synced_at',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:4',
        'stock_quantity' => 'decimal:4',
        'vat_rate' => 'decimal:2',
        'warning_flag' => 'boolean',
        'raw_payload' => 'array',
        'normalized_payload' => 'array',
        'warnings' => 'array',
        'errors' => 'array',
        'source_price' => 'decimal:2',
        'source_stock' => 'integer',
        'source_attributes' => 'array',
        'sync_status' => 'string',
        'synced_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the supplier that owns the raw product
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the source that owns the raw product
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(SupplierSource::class, 'supplier_source_id');
    }

    /**
     * Get the standard product for this raw product
     */
    public function standardProduct(): BelongsTo
    {
        return $this->belongsTo(StandardProduct::class, 'standard_product_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(SupplierProductVariantRaw::class, 'supplier_product_raw_id');
    }

    /**
     * Scope to get pending products
     */
    public function scopePending($query)
    {
        return $query->where('sync_status', 'pending');
    }

    /**
     * Scope to get processed products
     */
    public function scopeProcessed($query)
    {
        return $query->where('sync_status', 'processed');
    }

    /**
     * Scope to get products with errors
     */
    public function scopeWithError($query)
    {
        return $query->where('sync_status', 'error');
    }

    /**
     * Scope to get skipped products
     */
    public function scopeSkipped($query)
    {
        return $query->where('sync_status', 'skipped');
    }

    /**
     * Scope to get products by category
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('source_category', $category);
    }

    /**
     * Scope to get products with stock
     */
    public function scopeWithStock($query)
    {
        return $query->where('source_stock', '>', 0);
    }

    /**
     * Scope to get products without stock
     */
    public function scopeWithoutStock($query)
    {
        return $query->where(function ($query) {
            $query->whereNull('source_stock')
                  ->orWhere('source_stock', '<=', 0);
        });
    }

    /**
     * Check if product is pending
     */
    public function isPending(): bool
    {
        return $this->sync_status === 'pending';
    }

    /**
     * Check if product is processed
     */
    public function isProcessed(): bool
    {
        return $this->sync_status === 'processed';
    }

    /**
     * Check if product has error
     */
    public function hasError(): bool
    {
        return $this->sync_status === 'error';
    }

    /**
     * Check if product is skipped
     */
    public function isSkipped(): bool
    {
        return $this->sync_status === 'skipped';
    }

    /**
     * Check if product has stock
     */
    public function hasStock(): bool
    {
        return $this->source_stock > 0;
    }

    /**
     * Get the sync status display name
     */
    public function getSyncStatusDisplayName(): string
    {
        return match($this->sync_status) {
            'pending' => 'Bekliyor',
            'staged' => 'Ham Havuz',
            'processed' => 'İşlendi',
            'error' => 'Hata',
            'skipped' => 'Atlandı',
            default => ucfirst($this->sync_status),
        };
    }

    /**
     * Get the formatted price
     */
    public function getFormattedPrice(): string
    {
        $price = $this->purchase_price ?? $this->source_price;

        if (!$price) {
            return 'Fiyat Yok';
        }

        $currency = $this->currency ?? $this->source_currency ?? 'TL';
        return number_format((float) $price, 2, ',', '.') . ' ' . $currency;
    }

    /**
     * Get the formatted stock
     */
    public function getFormattedStock(): string
    {
        $stock = $this->stock_quantity ?? $this->source_stock;

        if (is_null($stock)) {
            return 'Stok Yok';
        }

        return number_format((float) $stock, 0, '.', '.') . ' Adet';
    }
}
