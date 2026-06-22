<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryAlias extends Model
{
    use HasFactory;

    protected $fillable = [
        'standard_category_id',
        'supplier_id',
        'alias_name',
        'normalized_alias',
        'source_type',
        'confidence_score',
        'is_active',
    ];

    protected $casts = [
        'confidence_score' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function standardCategory(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
