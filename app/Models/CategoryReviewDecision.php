<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryReviewDecision extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_code',
        'supplier_category_mapping_id',
        'supplier',
        'supplier_category_code',
        'supplier_category_name',
        'supplier_category_path',
        'suggested_target_category_id',
        'final_target_category_id',
        'suggested_decision',
        'final_decision',
        'suggested_feature',
        'final_feature',
        'user_decision_status',
        'user_note',
        'decided_by',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(SupplierCategoryMapping::class, 'supplier_category_mapping_id');
    }

    public function suggestedTargetCategory(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'suggested_target_category_id');
    }

    public function finalTargetCategory(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'final_target_category_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
