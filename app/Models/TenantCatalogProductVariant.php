<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\ProductDisplayNameFormatter;

class TenantCatalogProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'tenant_catalog_product_id',
        'standard_product_variant_id',
        'variant_code',
        'variant_name',
        'variant_color',
        'variant_size',
        'image_url',
        'display_price',
        'currency',
        'stock_quantity',
        'local_stock_quantity',
        'supplier_stock_quantity',
        'safe_stock_quantity',
        'visible_in_catalog',
        'is_active',
        'source_summary',
        'meta',
    ];

    protected $casts = [
        'display_price' => 'decimal:4',
        'stock_quantity' => 'decimal:4',
        'local_stock_quantity' => 'decimal:4',
        'supplier_stock_quantity' => 'decimal:4',
        'safe_stock_quantity' => 'integer',
        'visible_in_catalog' => 'boolean',
        'is_active' => 'boolean',
        'source_summary' => 'array',
        'meta' => 'array',
    ];

    protected $appends = [
        'display_name',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(TenantCatalogProduct::class, 'tenant_catalog_product_id');
    }

    public function standardVariant(): BelongsTo
    {
        return $this->belongsTo(StandardProductVariant::class, 'standard_product_variant_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(TenantCatalogProductImage::class, 'tenant_catalog_product_variant_id');
    }

    public function getDisplayNameAttribute(): string
    {
        return ProductDisplayNameFormatter::variant(
            $this->variant_code,
            data_get($this->meta, 'parent_product_name') ?: $this->catalogProduct?->product_name ?: $this->catalogProduct?->name,
            $this->variant_name,
            $this->variant_color,
            $this->variant_size,
            data_get($this->meta, 'variant_attributes.measure'),
            data_get($this->meta, 'variant_attributes.capacity'),
            data_get($this->meta, 'variant_attributes.option'),
            [
                $this->catalogProduct?->product_code,
                $this->catalogProduct?->tenant_sku,
                data_get($this->meta, 'supplier_product_code'),
                data_get($this->meta, 'parent_product_code'),
            ]
        );
    }
}
