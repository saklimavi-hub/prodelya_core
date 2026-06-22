<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedSyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'supplier_id',
        'supplier_source_id',
        'sync_type',
        'started_at',
        'completed_at',
        'status',
        'total_products',
        'processed_products',
        'failed_products',
        'skipped_products',
        'new_products',
        'updated_products',
        'sync_hash',
        'error_summary',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'status' => 'string',
        'total_products' => 'integer',
        'processed_products' => 'integer',
        'failed_products' => 'integer',
        'skipped_products' => 'integer',
        'new_products' => 'integer',
        'updated_products' => 'integer',
    ];

    /**
     * Get the tenant that owns the sync log
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the supplier that owns the sync log
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the source that owns the sync log
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(SupplierSource::class, 'supplier_source_id');
    }

    /**
     * Get the errors for this sync log
     */
    public function errors(): HasMany
    {
        return $this->hasMany(FeedSyncError::class);
    }

    /**
     * Scope to get successful syncs
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    /**
     * Scope to get failed syncs
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope to get running syncs
     */
    public function scopeRunning($query)
    {
        return $query->where('status', 'running');
    }

    /**
     * Scope to get syncs by type
     */
    public function scopeByType($query, $type)
    {
        return $query->where('sync_type', $type);
    }

    /**
     * Scope to get recent syncs
     */
    public function scopeRecent($query, $days = 7)
    {
        return $query->where('started_at', '>=', now()->subDays($days));
    }

    /**
     * Check if sync is successful
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }

    /**
     * Check if sync is failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if sync is running
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Check if sync has completed
     */
    public function isCompleted(): bool
    {
        return !is_null($this->completed_at);
    }

    /**
     * Get the sync duration in seconds
     */
    public function getDuration(): ?int
    {
        if (!$this->completed_at) {
            return null;
        }

        return $this->completed_at->diffInSeconds($this->started_at);
    }

    /**
     * Get the success rate percentage
     */
    public function getSuccessRate(): ?float
    {
        if ($this->total_products === 0) {
            return null;
        }

        return round(($this->processed_products / $this->total_products) * 100, 2);
    }

    /**
     * Get the status display name
     */
    public function getStatusDisplayName(): string
    {
        return match($this->status) {
            'success' => 'Başarılı',
            'failed' => 'Başarısız',
            'running' => 'Çalışıyor',
            'cancelled' => 'İptal Edildi',
            default => ucfirst($this->status),
        };
    }

    /**
     * Get the sync type display name
     */
    public function getSyncTypeDisplayName(): string
    {
        return match($this->sync_type) {
            'full' => 'Tam Senkronizasyon',
            'incremental' => 'Artımlı Senkronizasyon',
            'manual' => 'Manuel Senkronizasyon',
            'scheduled' => 'Zamanlanmış Senkronizasyon',
            default => ucfirst($this->sync_type),
        };
    }

    /**
     * Get the formatted duration
     */
    public function getFormattedDuration(): string
    {
        $duration = $this->getDuration();
        
        if ($duration === null) {
            return 'Devam Ediyor';
        }

        if ($duration < 60) {
            return $duration . ' saniye';
        } elseif ($duration < 3600) {
            return floor($duration / 60) . ' dakika ' . ($duration % 60) . ' saniye';
        } else {
            $hours = floor($duration / 3600);
            $minutes = floor(($duration % 3600) / 60);
            return $hours . ' saat ' . $minutes . ' dakika';
        }
    }
}
