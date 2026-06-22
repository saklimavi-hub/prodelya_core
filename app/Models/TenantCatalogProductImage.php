<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantCatalogProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'tenant_catalog_product_id',
        'tenant_catalog_product_variant_id',
        'standard_product_image_id',
        'image_url',
        'image_type',
        'sort_order',
        'is_primary',
        'fallback_used',
        'visible_in_catalog',
        'meta',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_primary' => 'boolean',
        'fallback_used' => 'boolean',
        'visible_in_catalog' => 'boolean',
        'meta' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(TenantCatalogProduct::class, 'tenant_catalog_product_id');
    }

    public function catalogProductVariant(): BelongsTo
    {
        return $this->belongsTo(TenantCatalogProductVariant::class, 'tenant_catalog_product_variant_id');
    }

    public function standardProductImage(): BelongsTo
    {
        return $this->belongsTo(StandardProductImage::class);
    }
}
