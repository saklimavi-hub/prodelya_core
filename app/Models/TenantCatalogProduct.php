<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Support\ProductDisplayNameFormatter;
use Illuminate\Support\Str;

class TenantCatalogProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'standard_product_id',
        'tenant_sku',
        'name',
        'description',
        'sale_price',
        'stock_quantity',
        'allow_backorder',
        'min_order_quantity',
        'tenant_attributes',
        'product_code',
        'product_name',
        'slug',
        'standard_category_id',
        'product_family',
        'image_url',
        'product_url',
        'detail_url',
        'display_price',
        'currency',
        'total_stock_quantity',
        'local_stock_quantity',
        'supplier_stock_quantity',
        'safe_stock_quantity',
        'price_multiplier',
        'source_summary',
        'visible_in_catalog',
        'visible_in_quote',
        'hidden_reason',
        'is_featured',
        'local_stock_priority',
        'catalog_source',
        'catalog_status',
        'last_synced_at',
        'meta',
        'is_active',
        'sort_order',
        'meta_title',
        'meta_description',
        'search_keywords',
    ];

    protected $casts = [
        'display_price' => 'decimal:4',
        'sale_price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'allow_backorder' => 'boolean',
        'min_order_quantity' => 'decimal:4',
        'tenant_attributes' => 'array',
        'total_stock_quantity' => 'decimal:4',
        'local_stock_quantity' => 'decimal:4',
        'supplier_stock_quantity' => 'decimal:4',
        'safe_stock_quantity' => 'integer',
        'price_multiplier' => 'decimal:4',
        'source_summary' => 'array',
        'visible_in_catalog' => 'boolean',
        'visible_in_quote' => 'boolean',
        'is_featured' => 'boolean',
        'local_stock_priority' => 'boolean',
        'meta' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    /**
     * Get the tenant that owns the catalog product
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the standard product that owns the catalog product
     */
    public function standardProduct(): BelongsTo
    {
        return $this->belongsTo(StandardProduct::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'standard_category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(TenantCatalogProductVariant::class, 'tenant_catalog_product_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(TenantCatalogProductImage::class, 'tenant_catalog_product_id');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(TenantCatalogProductImage::class, 'tenant_catalog_product_id')->where('is_primary', true)->orderBy('sort_order');
    }

    /**
     * Get the local stocks for this catalog product
     */
    public function localStocks(): HasMany
    {
        return $this->hasMany(TenantLocalStock::class, 'tenant_catalog_product_id');
    }


    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'tenant_catalog_product_id');
    }
    /**
     * Get the price snapshots for this catalog product
     */
    public function priceSnapshots(): HasMany
    {
        return $this->hasMany(ProductPriceSnapshot::class);
    }

    /**
     * Scope to get active catalog products
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeVisible($query)
    {
        return $query->where('visible_in_catalog', true);
    }

    public function scopeVisibleInQuote($query)
    {
        return $query->where('visible_in_quote', true);
    }

    /**
     * Scope to get inactive catalog products
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope to get featured products
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * Scope to get products by sort order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Check if catalog product is active
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Check if catalog product is featured
     */
    public function isFeatured(): bool
    {
        return $this->is_featured;
    }

    /**
     * Get the formatted selling price
     */
    public function getFormattedSellingPrice(): string
    {
        $price = $this->display_price ?? $this->selling_price;
        $price = $price ?? $this->sale_price;

        if (!$price) {
            return 'Fiyat Yok';
        }

        $currency = $this->currency ?? 'TL';
        return number_format((float) $price, 2, ',', '.') . ' ' . $currency;
    }

    /**
     * Get the primary image
     */
    public function getPrimaryImage(): ?string
    {
        $relationImage = $this->relationLoaded('primaryImage') ? $this->primaryImage : $this->primaryImage()->first();
        if ($relationImage) {
            return $relationImage->image_url;
        }

        $images = $this->tenant_attributes['catalog_images'] ?? null;

        if (!$images || empty($images)) {
            return null;
        }

        return $images[0];
    }

    public function getDisplayNameAttribute(): string
    {
        return ProductDisplayNameFormatter::format([
            'supplier_name' => data_get($this->source_summary, '0.supplier_name'),
            'product_code' => $this->product_code ?: $this->tenant_sku ?: $this->standardProduct?->standard_product_code,
            'sku' => $this->tenant_sku ?: $this->standardProduct?->sku,
            'supplier_product_code' => data_get($this->source_summary, '0.supplier_product_code'),
            'supplier_group_code' => data_get($this->source_summary, '0.supplier_group_code'),
            'raw_product_name' => $this->product_name ?: $this->name ?: $this->standardProduct?->product_name ?: $this->standardProduct?->base_product_name,
            'normalized_product_name' => data_get($this->meta, 'normalized_payload.product_name'),
            'category_name' => $this->category_display_name,
            'source_summary' => $this->source_summary,
            'meta' => $this->meta,
        ])['display_name'];
    }

    public function getCategoryDisplayNameAttribute(): string
    {
        $relationCategory = $this->relationLoaded('category')
            ? $this->getRelation('category')
            : ($this->standard_category_id ? $this->category()->first() : null);

        $resolved = $this->resolveCategoryDisplayCandidate($relationCategory)
            ?? $this->resolveCategoryDisplayCandidate($this->standardProduct?->category_display_name)
            ?? $this->resolveCategoryDisplayCandidate(data_get($this->meta, 'standard_category_name'))
            ?? $this->resolveCategoryDisplayCandidate(data_get($this->meta, 'category_name'))
            ?? $this->resolveCategoryDisplayCandidate(data_get($this->meta, 'supplier_category_name'))
            ?? $this->resolveCategoryDisplayCandidate(data_get($this->meta, 'supplier_category_path'))
            ?? $this->resolveCategoryDisplayCandidate(data_get($this->source_summary, '0.standard_category_name'))
            ?? $this->resolveCategoryDisplayCandidate(data_get($this->source_summary, '0.category_name'))
            ?? $this->resolveCategoryDisplayCandidate(data_get($this->source_summary, '0.supplier_category_name'))
            ?? $this->resolveCategoryDisplayCandidate(data_get($this->source_summary, '0.supplier_category_path'));

        return $resolved ?: '-';
    }

    public function getDisplayCodeAttribute(): string
    {
        return $this->product_code
            ?: $this->tenant_sku
            ?: $this->standardProduct?->standard_product_code
            ?: '-';
    }

    public function getCatalogSourceLabelAttribute(): string
    {
        return match ($this->catalog_source) {
            'local_product' => 'Local Ürün',
            default => 'Tedarikçi Ürünü',
        };
    }

    public function getEffectiveStockQuantityAttribute(): float
    {
        $localStock = (float) ($this->local_stock_quantity ?? 0);
        $localStockPriority = (bool) ($this->local_stock_priority ?? true);

        if ($localStockPriority && $localStock > 0) {
            return $localStock;
        }

        $supplierStock = (float) ($this->supplier_stock_quantity ?? $this->total_stock_quantity ?? 0);

        if ($supplierStock > 0) {
            return $supplierStock;
        }

        return max(0, $localStock);
    }

    public function getHasLocalStockPriorityAttribute(): bool
    {
        return (bool) ($this->local_stock_priority ?? true)
            && (float) ($this->local_stock_quantity ?? 0) > 0;
    }

    public function getHasWarningsAttribute(): bool
    {
        return !empty($this->meta['warning_snapshot'] ?? [])
            || !empty($this->meta['warnings'] ?? [])
            || empty($this->image_url)
            || blank($this->standard_category_id)
            || (bool) data_get($this->meta, 'category_missing_warning', false)
            || data_get($this->meta, 'fallback_category_code') === 'PROMO-ESLENMEMIS-KATEGORI-BEKLEYEN'
            || is_null($this->display_price);
    }

    public function getIsParentGroupAttribute(): bool
    {
        if (array_key_exists('is_parent', (array) ($this->meta ?? []))) {
            return (bool) data_get($this->meta, 'is_parent', false);
        }

        return $this->relationLoaded('variants')
            ? $this->variants->isNotEmpty()
            : $this->variants()->exists();
    }

    public function getIsSellableAttribute(): bool
    {
        if (array_key_exists('is_sellable', (array) ($this->meta ?? []))) {
            return (bool) data_get($this->meta, 'is_sellable', true);
        }

        return !$this->is_parent_group;
    }

    /**
     * Get the thumbnail image
     */
    public function getThumbnailImage(): ?string
    {
        $primaryImage = $this->getPrimaryImage();

        if (!$primaryImage) {
            return null;
        }

        // Add thumbnail suffix or resize logic here
        return $primaryImage;
    }

    private function resolveCategoryDisplayCandidate(mixed $candidate): ?string
    {
        if (blank($candidate)) {
            return null;
        }

        if (is_string($candidate)) {
            $value = trim($candidate);

            return $value !== '' ? $value : null;
        }

        if (is_array($candidate)) {
            return $this->resolveCategoryDisplayCandidate(
                $candidate['full_path']
                ?? $candidate['path']
                ?? $candidate['name']
                ?? null
            );
        }

        if (is_object($candidate)) {
            return $this->resolveCategoryDisplayCandidate(
                data_get($candidate, 'full_path')
                ?? data_get($candidate, 'path')
                ?? data_get($candidate, 'name')
            );
        }

        return null;
    }
}
