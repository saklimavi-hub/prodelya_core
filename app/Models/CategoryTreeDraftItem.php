<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoryTreeDraftItem extends Model
{
    protected $fillable = [
        'draft_id',
        'parent_id',
        'proposed_code',
        'proposed_name',
        'product_family',
        'sort_order',
        'is_visible',
        'is_active',
        'source_category_id',
        'canonical_target_id',
        'action_type',
        'notes',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(CategoryTreeDraft::class, 'draft_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('proposed_name');
    }

    public function sourceCategory(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'source_category_id');
    }

    public function canonicalTarget(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'canonical_target_id');
    }
}
