<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProductVariantRaw extends Model
{
    use HasFactory;

    protected $table = 'supplier_product_variants_raw';

    protected $fillable = [
        'tenant_account_id',
        'supplier_id',
        'supplier_source_id',
        'supplier_product_raw_id',
        'standard_product_variant_id',
        'parent_supplier_product_id',
        'supplier_group_code',
        'variant_id',
        'variant_code',
        'variant_stock_code',
        'variant_name',
        'variant_color',
        'variant_size',
        'variant_attributes',
        'variant_stock_quantity',
        'variant_image_url',
        'parent_image_url',
        'image_fallback_used',
        'generated_variant_code',
        'raw_payload',
        'normalized_payload',
        'warnings',
        'errors',
        'import_hash',
        'identity_hash',
        'content_hash',
        'price_hash',
        'stock_hash',
        'image_hash',
        'category_hash',
        'sync_status',
    ];

    protected $casts = [
        'variant_attributes' => 'array',
        'variant_stock_quantity' => 'decimal:4',
        'image_fallback_used' => 'boolean',
        'raw_payload' => 'array',
        'normalized_payload' => 'array',
        'warnings' => 'array',
        'errors' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(SupplierSource::class, 'supplier_source_id');
    }

    public function rawProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProductRaw::class, 'supplier_product_raw_id');
    }

    public function standardProductVariant(): BelongsTo
    {
        return $this->belongsTo(StandardProductVariant::class, 'standard_product_variant_id');
    }
}
