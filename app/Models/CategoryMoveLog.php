<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryMoveLog extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $fillable = [
        'category_id',
        'old_parent_id',
        'new_parent_id',
        'old_path',
        'new_path',
        'old_sort_order',
        'new_sort_order',
        'moved_by',
        'moved_at',
        'notes',
    ];

    protected $casts = [
        'moved_at' => 'datetime',
        'old_sort_order' => 'integer',
        'new_sort_order' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'category_id');
    }

    public function oldParent(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'old_parent_id');
    }

    public function newParent(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class, 'new_parent_id');
    }

    public function mover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }
}
