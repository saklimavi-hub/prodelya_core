<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryMergeLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'source_category_id',
        'target_category_id',
        'old_code',
        'new_code',
        'moved_products_count',
        'moved_mappings_count',
        'moved_aliases_count',
        'merged_by',
        'merged_at',
        'notes',
    ];

    protected $casts = [
        'moved_products_count' => 'integer',
        'moved_mappings_count' => 'integer',
        'moved_aliases_count' => 'integer',
        'merged_at' => 'datetime',
    ];

    public function sourceCategory(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'source_category_id');
    }

    public function targetCategory(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'target_category_id');
    }
}
