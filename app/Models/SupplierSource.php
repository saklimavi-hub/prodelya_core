<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'source_type',
        'source_name',
        'url',
        'config',
        'status',
        'last_sync_at',
        'last_error',
    ];

    protected $casts = [
        'config' => 'array',
        'status' => 'string',
        'last_sync_at' => 'datetime',
    ];

    /**
     * Get the supplier that owns the source
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the raw products for this source
     */
    public function rawProducts(): HasMany
    {
        return $this->hasMany(SupplierProductRaw::class);
    }

    public function rawVariants(): HasMany
    {
        return $this->hasMany(SupplierProductVariantRaw::class, 'supplier_source_id');
    }

    /**
     * Get the sync logs for this source
     */
    public function syncLogs(): HasMany
    {
        return $this->hasMany(FeedSyncLog::class);
    }

    public function categoryMappings(): HasMany
    {
        return $this->hasMany(SupplierCategoryMapping::class);
    }

    public function fieldMappings(): HasMany
    {
        return $this->hasMany(SupplierFieldMapping::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(ProductDataHubSyncRun::class, 'supplier_source_id');
    }

    /**
     * Scope to get active sources
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get inactive sources
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Scope to get sources with errors
     */
    public function scopeWithError($query)
    {
        return $query->where('status', 'error');
    }

    /**
     * Scope to get XML sources
     */
    public function scopeXml($query)
    {
        return $query->where('source_type', 'xml');
    }

    /**
     * Scope to get API sources
     */
    public function scopeApi($query)
    {
        return $query->where('source_type', 'api');
    }

    /**
     * Scope to get CSV sources
     */
    public function scopeCsv($query)
    {
        return $query->where('source_type', 'csv');
    }

    public function scopeNotArchived($query)
    {
        return $query
            ->where(function ($innerQuery) {
                $innerQuery->whereNull('config->lifecycle_state')
                    ->orWhereNotIn('config->lifecycle_state', ['archived', 'inactive', 'passive']);
            })
            ->whereNotIn('status', ['archived', 'passive']);
    }

    public function scopeNotTemp($query)
    {
        return $query
            ->where(function ($innerQuery) {
                $innerQuery->whereNull('config->profile_key')
                    ->orWhere(function ($profileQuery) {
                        $profileQuery->where('config->profile_key', 'not like', 'TMP-%')
                            ->where('config->profile_key', 'not like', 'TEST-%')
                            ->where('config->profile_key', 'not like', 'DEMO-%');
                    });
            })
            ->whereHas('supplier', function ($supplierQuery) {
                $supplierQuery->where(function ($innerSupplierQuery) {
                    $innerSupplierQuery->where('code', 'not like', 'TMP-%')
                        ->where('code', 'not like', 'TEST-%')
                        ->where('code', 'not like', 'DEMO-%');
                });
            })
            ->where(function ($nameQuery) {
                $nameQuery->whereNull('source_name')
                    ->orWhere(function ($innerNameQuery) {
                        $innerNameQuery->where('source_name', 'not like', '%Temp%')
                            ->where('source_name', 'not like', '%Demo%')
                            ->where('source_name', 'not like', '%Test%');
                    });
            });
    }

    public function scopeReal($query)
    {
        return $query->notTemp();
    }

    public function scopeVisibleInProductDataHub($query)
    {
        return $query
            ->active()
            ->notArchived()
            ->notTemp();
    }

    public function scopeSelectable($query)
    {
        return $query->visibleInProductDataHub();
    }

    /**
     * Check if source is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if source has error
     */
    public function hasError(): bool
    {
        return $this->status === 'error';
    }

    /**
     * Get the source type display name
     */
    public function getSourceTypeDisplayName(): string
    {
        return match($this->source_type) {
            'xml' => 'XML',
            'api' => 'API',
            'csv' => 'CSV',
            'excel' => 'Excel',
            'manual' => 'Manual',
            default => ucfirst($this->source_type),
        };
    }

    /**
     * Get the status display name
     */
    public function getStatusDisplayName(): string
    {
        return match($this->status) {
            'active' => 'Aktif',
            'inactive' => 'Pasif',
            'error' => 'Hata',
            default => ucfirst($this->status),
        };
    }
}
