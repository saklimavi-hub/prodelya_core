<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierCategoryMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'supplier_source_id',
        'standard_category_id',
        'supplier_category_code',
        'source_category',
        'supplier_category_path',
        'supplier_category_level',
        'normalized_name',
        'product_count',
        'sample_product_names',
        'sample_image_urls',
        'suggestion_meta',
        'target_category',
        'description',
        'is_active',
        'mapping_status',
        'decision_type',
        'decision_note',
        'confidence_score',
        'reviewed_by',
        'reviewed_at',
        'last_scanned_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'confidence_score' => 'decimal:2',
        'product_count' => 'integer',
        'supplier_category_level' => 'integer',
        'sample_product_names' => 'array',
        'sample_image_urls' => 'array',
        'suggestion_meta' => 'array',
        'reviewed_at' => 'datetime',
        'last_scanned_at' => 'datetime',
    ];

    /**
     * Get the supplier that owns the mapping
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function supplierSource(): BelongsTo
    {
        return $this->belongsTo(SupplierSource::class);
    }

    public function source(): BelongsTo
    {
        return $this->supplierSource();
    }

    public function standardCategory(): BelongsTo
    {
        return $this->belongsTo(StandardCategory::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->reviewedBy();
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SupplierCategoryMappingLog::class, 'mapping_id')->latest();
    }

    public function tenant()
    {
        // TODO: supplier_category_mappings tablosunda tenant_account_id yok.
        // Tenant izolasyonu controller seviyesinde supplier access ile uygulanir.
        return null;
    }

    /**
     * Scope to get approved mappings
     */
    public function scopeApproved($query)
    {
        return $query->whereIn('mapping_status', ['approved', 'auto_approved', 'mapped']);
    }

    /**
     * Scope to get pending mappings
     */
    public function scopePending($query)
    {
        return $query->where(function ($builder) {
            $builder->whereNull('mapping_status')
                ->orWhere('mapping_status', 'pending');
        });
    }

    /**
     * Scope to get high confidence mappings
     */
    public function scopeHighConfidence($query)
    {
        return $query->where('confidence_score', '>=', 80);
    }

    /**
     * Scope to get low confidence mappings
     */
    public function scopeLowConfidence($query)
    {
        return $query->where('confidence_score', '<', 50);
    }

    /**
     * Check if mapping is approved
     */
    public function isApproved(): bool
    {
        return in_array($this->mapping_status, ['approved', 'auto_approved', 'mapped'], true);
    }

    /**
     * Get the confidence level display
     */
    public function getConfidenceLevel(): string
    {
        if (($this->confidence_score ?? 0) >= 80) {
            return 'Yüksek';
        } elseif (($this->confidence_score ?? 0) >= 50) {
            return 'Orta';
        } else {
            return 'Düşük';
        }
    }
}
