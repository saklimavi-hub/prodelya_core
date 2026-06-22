<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierCategoryMappingLog extends Model
{
    protected $fillable = [
        'mapping_id',
        'old_standard_category_id',
        'new_standard_category_id',
        'action',
        'reason',
        'changed_by',
    ];

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(SupplierCategoryMapping::class, 'mapping_id');
    }

    public function oldStandardCategory(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'old_standard_category_id');
    }

    public function newStandardCategory(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'new_standard_category_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
