<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryCleanupDecision extends Model
{
    use HasFactory;

    protected $fillable = [
        'draft_id',
        'current_category_id',
        'current_code',
        'current_name',
        'current_path',
        'current_parent',
        'level',
        'product_family',
        'product_count',
        'supplier_mapping_count',
        'child_count',
        'is_active',
        'is_visible',
        'warning_flags',
        'proposed_action',
        'proposed_category_code',
        'proposed_category_name',
        'proposed_category_path',
        'proposed_parent',
        'decision_status',
        'confidence_score',
        'reason',
        'risk_level',
        'needs_user_review',
        'feature_template_key',
    ];

    protected $casts = [
        'level' => 'integer',
        'product_count' => 'integer',
        'supplier_mapping_count' => 'integer',
        'child_count' => 'integer',
        'is_active' => 'boolean',
        'is_visible' => 'boolean',
        'warning_flags' => 'array',
        'confidence_score' => 'decimal:2',
        'needs_user_review' => 'boolean',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(CategoryTreeDraft::class, 'draft_id');
    }

    public function currentCategory(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'current_category_id');
    }
}
