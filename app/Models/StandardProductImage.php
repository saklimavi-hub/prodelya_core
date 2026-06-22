<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StandardProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'standard_product_id',
        'standard_product_variant_id',
        'image_url',
        'image_type',
        'sort_order',
        'is_primary',
        'fallback_used',
        'source_supplier_id',
        'source_supplier_source_id',
        'source_raw_product_id',
        'source_raw_variant_id',
        'meta',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_primary' => 'boolean',
        'fallback_used' => 'boolean',
        'meta' => 'array',
    ];

    public function standardProduct(): BelongsTo
    {
        return $this->belongsTo(StandardProduct::class);
    }

    public function standardProductVariant(): BelongsTo
    {
        return $this->belongsTo(StandardProductVariant::class);
    }
}
