<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductDataHubSyncChange extends Model
{
    use HasFactory;

    public const REVIEW_STATUS_PENDING = 'pending_review';
    public const REVIEW_STATUS_REVIEWED = 'reviewed';
    public const REVIEW_STATUS_APPROVED_FOR_STANDARD_BUILD = 'approved_for_standard_build';
    public const REVIEW_STATUS_APPROVED_FOR_PROJECTION = 'approved_for_projection';
    public const REVIEW_STATUS_IGNORED = 'ignored';
    public const REVIEW_STATUS_PASSIVE_CANDIDATE = 'passive_candidate';
    public const REVIEW_STATUS_RESOLVED = 'resolved';

    public const REVIEWABLE_CHANGE_TYPES = [
        'new_product',
        'new_variant',
        'missing_product',
        'missing_variant',
        'category_changed',
        'image_changed',
        'content_changed',
        'variant_structure_changed',
    ];

    protected $fillable = [
        'sync_run_id',
        'supplier_source_id',
        'supplier_product_key',
        'standard_product_id',
        'change_type',
        'old_value',
        'new_value',
        'message',
        'review_status',
        'review_payload',
        'reviewed_at',
        'resolved_at',
        'missing_feed_run_count',
        'is_passive_candidate',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'review_payload' => 'array',
        'reviewed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'is_passive_candidate' => 'boolean',
    ];

    public function scopeOpenReview($query)
    {
        return $query->whereIn('review_status', [
            self::REVIEW_STATUS_PENDING,
            self::REVIEW_STATUS_REVIEWED,
            self::REVIEW_STATUS_APPROVED_FOR_STANDARD_BUILD,
            self::REVIEW_STATUS_APPROVED_FOR_PROJECTION,
            self::REVIEW_STATUS_PASSIVE_CANDIDATE,
        ]);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductDataHubSyncRun::class, 'sync_run_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(SupplierSource::class, 'supplier_source_id');
    }

    public function standardProduct(): BelongsTo
    {
        return $this->belongsTo(StandardProduct::class, 'standard_product_id');
    }
}
