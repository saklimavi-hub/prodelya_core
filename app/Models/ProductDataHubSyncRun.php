<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductDataHubSyncRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_source_id',
        'supplier_id',
        'run_type',
        'started_at',
        'finished_at',
        'status',
        'records_read',
        'products_created',
        'products_updated',
        'products_unchanged',
        'products_missing_from_feed',
        'products_inactivated',
        'price_changed_count',
        'stock_changed_count',
        'image_changed_count',
        'category_changed_count',
        'name_changed_count',
        'description_changed_count',
        'warning_count',
        'error_count',
        'report_payload',
        'triggered_by',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'report_payload' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(SupplierSource::class, 'supplier_source_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ProductDataHubSyncChange::class, 'sync_run_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
