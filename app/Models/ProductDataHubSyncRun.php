<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductDataHubSyncRun extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_COMPLETED_WITH_WARNINGS = 'completed_with_warnings';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_STUCK = 'stuck';
    public const STATUS_RECOVERED = 'recovered';

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
        'updated_at' => 'datetime',
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

    public static function normalizeStatus(?string $status): string
    {
        return match ((string) $status) {
            'success' => self::STATUS_COMPLETED,
            'partial' => self::STATUS_COMPLETED_WITH_WARNINGS,
            'error' => self::STATUS_FAILED,
            default => (string) ($status ?: self::STATUS_PENDING),
        };
    }

    public function normalizedStatus(): string
    {
        return self::normalizeStatus($this->status);
    }

    public function isRunningLike(): bool
    {
        return $this->normalizedStatus() === self::STATUS_RUNNING;
    }

    public function isTerminal(): bool
    {
        return in_array($this->normalizedStatus(), [
            self::STATUS_COMPLETED,
            self::STATUS_COMPLETED_WITH_WARNINGS,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_STUCK,
            self::STATUS_RECOVERED,
        ], true);
    }
}
