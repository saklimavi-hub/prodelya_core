<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedSyncError extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'feed_sync_log_id',
        'supplier_id',
        'supplier_source_id',
        'supplier_product_raw_id',
        'error_type',
        'error_message',
        'error_context',
        'stack_trace',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'error_context' => 'array',
        'resolved_at' => 'datetime',
    ];

    /**
     * Get the tenant that owns the error
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the sync log that owns the error
     */
    public function syncLog(): BelongsTo
    {
        return $this->belongsTo(FeedSyncLog::class, 'feed_sync_log_id');
    }

    /**
     * Get the supplier that owns the error
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the source that owns the error
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(SupplierSource::class, 'supplier_source_id');
    }

    /**
     * Get the raw product that caused the error
     */
    public function rawProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProductRaw::class, 'supplier_product_raw_id');
    }

    /**
     * Scope to get resolved errors
     */
    public function scopeResolved($query)
    {
        return $query->whereNotNull('resolved_at');
    }

    /**
     * Scope to get unresolved errors
     */
    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * Scope to get errors by type
     */
    public function scopeByType($query, $type)
    {
        return $query->where('error_type', $type);
    }

    /**
     * Scope to get recent errors
     */
    public function scopeRecent($query, $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Scope to get critical errors
     */
    public function scopeCritical($query)
    {
        return $query->where('error_type', 'critical');
    }

    /**
     * Scope to get warning errors
     */
    public function scopeWarning($query)
    {
        return $query->where('error_type', 'warning');
    }

    /**
     * Check if error is resolved
     */
    public function isResolved(): bool
    {
        return !is_null($this->resolved_at);
    }

    /**
     * Check if error is critical
     */
    public function isCritical(): bool
    {
        return $this->error_type === 'critical';
    }

    /**
     * Check if error is a warning
     */
    public function isWarning(): bool
    {
        return $this->error_type === 'warning';
    }

    /**
     * Get the error type display name
     */
    public function getErrorTypeDisplayName(): string
    {
        return match($this->error_type) {
            'critical' => 'Kritik',
            'warning' => 'Uyarı',
            'info' => 'Bilgi',
            'validation' => 'Doğrulama',
            'network' => 'Ağ',
            'parse' => 'Ayrıştırma',
            'mapping' => 'Eşleme',
            'database' => 'Veritabanı',
            default => ucfirst($this->error_type),
        };
    }

    /**
     * Get the error severity badge
     */
    public function getSeverityBadge(): array
    {
        return match($this->error_type) {
            'critical' => ['color' => 'red', 'text' => 'Kritik'],
            'warning' => ['color' => 'yellow', 'text' => 'Uyarı'],
            'info' => ['color' => 'blue', 'text' => 'Bilgi'],
            default => ['color' => 'gray', 'text' => 'Genel'],
        };
    }

    /**
     * Get the truncated error message
     */
    public function getTruncatedMessage(int $length = 100): string
    {
        if (strlen($this->error_message) <= $length) {
            return $this->error_message;
        }

        return substr($this->error_message, 0, $length) . '...';
    }
}
