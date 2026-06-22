<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Support\ProductDisplayNameFormatter;
use Illuminate\Support\Str;

class StandardProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'supplier_id',
        'supplier_product_raw_id',
        'sku',
        'name',
        'description',
        'category',
        'images',
        'is_active',
        'standard_product_code',
        'base_product_name',
        'slug',
        'standard_category_id',
        'product_family',
        'image_url',
        'product_url',
        'detail_url',
        'vat_rate',
        'currency',
        'min_purchase_price',
        'max_purchase_price',
        'total_stock_quantity',
        'supplier_count',
        'variant_count',
        'warning_flag',
        'visible_in_catalog',
        'source_summary',
        'meta',
        'product_code',
        'product_name',
        'product_description',
        'category_id',
        'category_name',
        'brand',
        'unit',
        'purchase_price',
        'purchase_currency',
        'selling_price',
        'selling_currency',
        'stock_quantity',
        'min_stock_level',
        'images',
        'specifications',
        'tags',
        'status',
        'approved_at',
        'approved_by',
        'sync_hash',
    ];

    protected $casts = [
        'vat_rate' => 'decimal:2',
        'min_purchase_price' => 'decimal:4',
        'max_purchase_price' => 'decimal:4',
        'total_stock_quantity' => 'decimal:4',
        'supplier_count' => 'integer',
        'variant_count' => 'integer',
        'warning_flag' => 'boolean',
        'visible_in_catalog' => 'boolean',
        'source_summary' => 'array',
        'meta' => 'array',
        'purchase_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'min_stock_level' => 'integer',
        'images' => 'array',
        'specifications' => 'array',
        'tags' => 'array',
        'status' => 'string',
        'is_active' => 'boolean',
        'approved_at' => 'datetime',
    ];

    protected $appends = [
        'display_name',
        'category_display_name',
    ];

    /**
     * Get the tenant that owns the product
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the supplier that owns the product
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the raw product that was used to create this standard product
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'standard_category_id');
    }

    public function rawProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProductRaw::class, 'supplier_product_raw_id');
    }

    public function rawProducts(): HasMany
    {
        return $this->hasMany(SupplierProductRaw::class, 'standard_product_id');
    }

    public function supplierProducts(): HasMany
    {
        return $this->rawProducts();
    }

    public function variants(): HasMany
    {
        return $this->hasMany(StandardProductVariant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(StandardProductImage::class);
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(StandardProductImage::class)->where('is_primary', true)->orderBy('sort_order');
    }

    /**
     * Get the tenant catalog products for this standard product
     */
    public function tenantCatalogProducts(): HasMany
    {
        return $this->hasMany(TenantCatalogProduct::class);
    }

    /**
     * Get the local stock for this product
     */
    public function localStock(): HasOne
    {
        return $this->hasOne(TenantLocalStock::class);
    }

    /**
     * Scope to get active products
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeVisibleInCatalog($query)
    {
        return $query->where('visible_in_catalog', true);
    }

    /**
     * Scope to get approved products
     */
    public function scopeApproved($query)
    {
        return $query->whereNotNull('approved_at');
    }

    /**
     * Scope to get pending products
     */
    public function scopePending($query)
    {
        return $query->whereNull('approved_at');
    }

    /**
     * Scope to get products with low stock
     */
    public function scopeLowStock($query)
    {
        return $query->where('stock_quantity', '<=', 'min_stock_level');
    }

    /**
     * Scope to get products out of stock
     */
    public function scopeOutOfStock($query)
    {
        return $query->where('stock_quantity', '<=', 0);
    }

    /**
     * Scope to get products by category
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('category_name', $category);
    }

    /**
     * Scope to get products by brand
     */
    public function scopeByBrand($query, $brand)
    {
        return $query->where('brand', $brand);
    }

    /**
     * Check if product is active
     */
    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * Check if product is approved
     */
    public function isApproved(): bool
    {
        return !is_null($this->approved_at);
    }

    /**
     * Check if product has low stock
     */
    public function hasLowStock(): bool
    {
        return $this->stock_quantity <= $this->min_stock_level;
    }

    /**
     * Check if product is out of stock
     */
    public function isOutOfStock(): bool
    {
        return $this->stock_quantity <= 0;
    }

    /**
     * Get the status display name
     */
    public function getStatusDisplayName(): string
    {
        return $this->is_active ? 'Aktif' : 'Pasif';
    }

    /**
     * Get the formatted purchase price
     */
    public function getFormattedPurchasePrice(): string
    {
        $price = $this->min_purchase_price ?? $this->purchase_price;

        if (!$price) {
            return 'Fiyat Yok';
        }

        $currency = $this->currency ?? $this->purchase_currency ?? 'TL';
        return number_format((float) $price, 2, ',', '.') . ' ' . $currency;
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
     * Get the formatted stock quantity
     */
    public function getFormattedStockQuantity(): string
    {
        return number_format((float) ($this->total_stock_quantity ?? $this->stock_quantity ?? 0), 0, '.', '.') . ' ' . ($this->unit ?? 'Adet');
    }

    /**
     * Get the stock status badge
     */
    public function getStockStatusBadge(): array
    {
        $stock = (float) ($this->total_stock_quantity ?? $this->stock_quantity ?? 0);

        if ($stock <= 0) {
            return ['color' => 'red', 'text' => 'Stok Yok'];
        } elseif ($stock <= (float) ($this->min_stock_level ?? 0)) {
            return ['color' => 'yellow', 'text' => 'Düşük Stok'];
        } else {
            return ['color' => 'green', 'text' => 'Stok Var'];
        }
    }

    public function hasVariants(): bool
    {
        return (int) ($this->variant_count ?? 0) > 0 || $this->variants()->exists();
    }

    public function updateAggregateStats(): void
    {
        $variants = $this->variants()->get();
        $rawProducts = $this->rawProducts()->get();

        $this->variant_count = $variants->count();
        $this->supplier_count = (int) $rawProducts->pluck('supplier_id')->filter()->unique()->count();
        $this->total_stock_quantity = $variants->count() > 0
            ? $variants->sum(fn (StandardProductVariant $variant) => (float) ($variant->stock_quantity ?? 0))
            : $rawProducts->sum(fn (SupplierProductRaw $rawProduct) => (float) ($rawProduct->stock_quantity ?? 0));
        $this->min_purchase_price = $variants->count() > 0
            ? $variants->min('min_purchase_price')
            : $rawProducts->min('purchase_price');
        $this->max_purchase_price = $variants->count() > 0
            ? $variants->max('max_purchase_price')
            : $rawProducts->max('purchase_price');
        $this->warning_flag = $rawProducts->contains(fn (SupplierProductRaw $rawProduct) => (bool) $rawProduct->warning_flag)
            || $variants->contains(fn (StandardProductVariant $variant) => (bool) ($variant->meta['warning_flag'] ?? false));
        if (blank($this->image_url)) {
            $this->image_url = $variants->first()?->image_url;
        }
        if (blank($this->images) && filled($this->image_url)) {
            $this->images = [$this->image_url];
        }
        $primaryImage = $this->images()->where('is_primary', true)->orderBy('sort_order')->first();
        if ($primaryImage && blank($this->image_url)) {
            $this->image_url = $primaryImage->image_url;
        }
        $this->source_summary = $rawProducts->map(fn (SupplierProductRaw $rawProduct) => [
            'supplier_id' => $rawProduct->supplier_id,
            'supplier_source_id' => $rawProduct->supplier_source_id,
            'raw_product_id' => $rawProduct->id,
            'supplier_product_code' => $rawProduct->supplier_product_code,
            'supplier_group_code' => $rawProduct->supplier_group_code,
            'import_hash' => $rawProduct->import_hash,
            'stock_quantity' => $rawProduct->stock_quantity ?? data_get($rawProduct->normalized_payload, 'stock_quantity'),
            'total_variant_stock_quantity' => data_get($rawProduct->normalized_payload, 'total_variant_stock_quantity'),
            'purchase_price' => $rawProduct->purchase_price,
            'list_price' => data_get($rawProduct->normalized_payload, 'list_price'),
            'alternative_price' => data_get($rawProduct->normalized_payload, 'alternative_price'),
            'usd_price' => data_get($rawProduct->normalized_payload, 'usd_price'),
            'discount_rate' => data_get($rawProduct->normalized_payload, 'discount_rate'),
            'vat_rate' => data_get($rawProduct->normalized_payload, 'vat_rate', $rawProduct->vat_rate),
            'currency' => data_get($rawProduct->normalized_payload, 'currency', $rawProduct->currency),
            'price_policy_warning' => (bool) data_get($rawProduct->normalized_payload, 'price_policy_warning', false),
            'net_price_warning' => (bool) data_get($rawProduct->normalized_payload, 'net_price_warning', false),
            'pricing_policy_type' => data_get($rawProduct->normalized_payload, 'pricing_policy_type'),
            'supplier_warning_flag' => (bool) data_get($rawProduct->normalized_payload, 'supplier_warning_flag', false),
            'supplier_warning_type' => data_get($rawProduct->normalized_payload, 'supplier_warning_type'),
            'gallery_images' => data_get($rawProduct->normalized_payload, 'gallery_images', []),
            'gallery_source_fields' => data_get($rawProduct->normalized_payload, 'gallery_source_fields', []),
            'warnings' => $rawProduct->warnings ?? [],
        ])->values()->all();
        $meta = is_array($this->meta) ? $this->meta : [];
        $meta['stock_snapshot'] = [
            'stock_quantity' => $this->total_stock_quantity,
            'source_stock_quantity' => $rawProducts->sum(fn (SupplierProductRaw $rawProduct) => (float) ($rawProduct->stock_quantity ?? data_get($rawProduct->normalized_payload, 'stock_quantity') ?? 0)),
            'total_variant_stock_quantity' => $rawProducts->sum(fn (SupplierProductRaw $rawProduct) => (float) (data_get($rawProduct->normalized_payload, 'total_variant_stock_quantity') ?? 0)),
        ];
        $this->meta = $meta;
        $this->save();
    }

    public function getDisplayNameAttribute(): string
    {
        return ProductDisplayNameFormatter::format([
            'supplier_name' => $this->supplier?->name,
            'product_code' => $this->standard_product_code ?: $this->sku,
            'sku' => $this->sku,
            'supplier_product_code' => data_get($this->source_summary, '0.supplier_product_code'),
            'supplier_group_code' => data_get($this->source_summary, '0.supplier_group_code'),
            'raw_product_name' => $this->product_name ?: $this->base_product_name ?: $this->name,
            'normalized_product_name' => data_get($this->meta, 'normalized_payload.product_name'),
            'category_name' => $this->category_name ?: $this->category,
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
            ?? $this->resolveCategoryDisplayCandidate($this->getAttributes()['category'] ?? null)
            ?? $this->resolveCategoryDisplayCandidate($this->category_name)
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
