<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCategorySuggestionLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_source_id',
        'supplier_product_id',
        'supplier_product_code',
        'supplier_product_name',
        'supplier_category_name',
        'product_image_url',
        'suggested_category_id',
        'accepted_category_id',
        'confidence_score',
        'name_score',
        'category_score',
        'attribute_score',
        'code_score',
        'image_score',
        'history_score',
        'decision_status',
        'decision_reason',
        'raw_signals',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'confidence_score' => 'decimal:2',
        'name_score' => 'decimal:2',
        'category_score' => 'decimal:2',
        'attribute_score' => 'decimal:2',
        'code_score' => 'decimal:2',
        'image_score' => 'decimal:2',
        'history_score' => 'decimal:2',
        'raw_signals' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(SupplierSource::class, 'supplier_source_id');
    }

    public function suggestedCategory(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'suggested_category_id');
    }

    public function acceptedCategory(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'accepted_category_id');
    }
}
