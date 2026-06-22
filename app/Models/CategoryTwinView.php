<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryTwinView extends Model
{
    use HasFactory;

    protected $fillable = [
        'canonical_category_id',
        'visible_parent_category_id',
        'display_name',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function canonicalCategory(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'canonical_category_id');
    }

    public function visibleParentCategory(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'visible_parent_category_id');
    }
}
