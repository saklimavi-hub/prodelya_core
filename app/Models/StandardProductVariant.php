<?php

namespace App\Models;

use App\Services\ProductDataHub\ProductAttributeValueNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\ProductDisplayNameFormatter;

class StandardProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'standard_product_id',
        'tenant_account_id',
        'variant_code',
        'generated_variant_code',
        'variant_name',
        'variant_color',
        'variant_size',
        'variant_attributes',
        'image_url',
        'image_fallback_used',
        'stock_quantity',
        'min_purchase_price',
        'max_purchase_price',
        'supplier_count',
        'is_active',
        'visible_in_catalog',
        'source_summary',
        'meta',
    ];

    protected $casts = [
        'variant_attributes' => 'array',
        'image_fallback_used' => 'boolean',
        'stock_quantity' => 'decimal:4',
        'min_purchase_price' => 'decimal:4',
        'max_purchase_price' => 'decimal:4',
        'supplier_count' => 'integer',
        'is_active' => 'boolean',
        'visible_in_catalog' => 'boolean',
        'source_summary' => 'array',
        'meta' => 'array',
    ];

    protected $appends = [
        'display_name',
    ];

    public function standardProduct(): BelongsTo
    {
        return $this->belongsTo(StandardProduct::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function rawVariants(): HasMany
    {
        return $this->hasMany(SupplierProductVariantRaw::class, 'standard_product_variant_id');
    }

    public function tenantCatalogVariants(): HasMany
    {
        return $this->hasMany(TenantCatalogProductVariant::class, 'standard_product_variant_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(StandardProductImage::class, 'standard_product_variant_id');
    }

    public function getDisplayNameAttribute(): string
    {
        return ProductDisplayNameFormatter::variant(
            $this->generated_variant_code ?: $this->variant_code,
            $this->standardProduct?->base_product_name ?: $this->standardProduct?->product_name ?: $this->standardProduct?->name,
            $this->variant_name,
            $this->variant_color,
            $this->variant_size,
            data_get($this->variant_attributes, 'measure'),
            data_get($this->variant_attributes, 'capacity'),
            data_get($this->variant_attributes, 'option'),
            [
                $this->variant_code,
                $this->standardProduct?->standard_product_code,
                $this->standardProduct?->sku,
            ]
        );
    }

    public function getVariantColorAttribute($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return $value;
        }

        return app(ProductAttributeValueNormalizer::class)->normalizeDisplayValue($value, 'variant_color');
    }

    public function getVariantSizeAttribute($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return $value;
        }

        return app(ProductAttributeValueNormalizer::class)->normalizeDisplayValue($value, 'variant_size');
    }

    public function isVisible(): bool
    {
        return $this->is_active && $this->visible_in_catalog;
    }
}
