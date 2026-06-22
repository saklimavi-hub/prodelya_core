<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryAttributeRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'standard_category_id',
        'product_attribute_definition_id',
        'is_required',
        'is_filterable',
        'visible_in_catalog',
        'sort_order',
        'meta',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_filterable' => 'boolean',
        'visible_in_catalog' => 'boolean',
        'sort_order' => 'integer',
        'meta' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'standard_category_id');
    }

    public function attributeDefinition(): BelongsTo
    {
        return $this->belongsTo(ProductAttributeDefinition::class, 'product_attribute_definition_id');
    }
}
