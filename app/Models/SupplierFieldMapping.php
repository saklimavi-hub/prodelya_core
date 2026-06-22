<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class SupplierFieldMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'supplier_id',
        'supplier_source_id',
        'source_field',
        'legacy_field_name',
        'target_field',
        'field_type',
        'mapping_status',
        'confidence_score',
        'transform_rule',
        'transformation_rules',
        'note',
        'reviewed_by',
        'reviewed_at',
        'meta',
        'default_value',
        'is_required',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'transformation_rules' => 'array',
        'confidence_score' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'meta' => 'array',
    ];

    protected $appends = [
        'source_field_name',
        'standard_field_key',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(SupplierSource::class, 'supplier_source_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeRequired(Builder $query): Builder
    {
        return $query->where('is_required', true);
    }

    public function scopeOptional(Builder $query): Builder
    {
        return $query->where('is_required', false);
    }

    public function scopeForSource(Builder $query, int $sourceId): Builder
    {
        return $query->where('supplier_source_id', $sourceId);
    }

    public function getSourceFieldNameAttribute(): string
    {
        return (string) $this->source_field;
    }

    public function setSourceFieldNameAttribute(?string $value): void
    {
        $this->attributes['source_field'] = $value;
    }

    public function getStandardFieldKeyAttribute(): ?string
    {
        return $this->target_field;
    }

    public function setStandardFieldKeyAttribute(?string $value): void
    {
        $this->attributes['target_field'] = $value;
    }

    public function getMappingTypeDisplayName(): string
    {
        return match($this->field_type) {
            'direct' => 'Doğrudan',
            'transform' => 'Dönüşüm',
            'lookup' => 'Arama',
            'calculated' => 'Hesaplanmış',
            default => ucfirst((string) $this->field_type),
        };
    }
}
