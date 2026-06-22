<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductAttributeDefinition extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'type',
        'unit',
        'is_filterable',
        'is_required',
        'sort_order',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'is_filterable' => 'boolean',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'meta' => 'array',
    ];

    public function categoryRules(): HasMany
    {
        return $this->hasMany(CategoryAttributeRule::class, 'product_attribute_definition_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            StandardCategory::class,
            'category_attribute_rules',
            'product_attribute_definition_id',
            'standard_category_id'
        )->withPivot(['is_required', 'is_filterable', 'visible_in_catalog', 'sort_order', 'meta'])->withTimestamps();
    }
}
