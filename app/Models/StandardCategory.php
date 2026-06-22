<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class StandardCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'canonical_category_id',
        'code',
        'name',
        'slug',
        'product_family',
        'description',
        'sort_order',
        'depth',
        'path',
        'is_active',
        'visible_in_catalog',
        'requires_mapping',
        'duplicate_status',
        'meta',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'depth' => 'integer',
        'is_active' => 'boolean',
        'visible_in_catalog' => 'boolean',
        'requires_mapping' => 'boolean',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (StandardCategory $category) {
            if (blank($category->slug)) {
                $category->slug = static::generateSlug($category->name);
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function canonicalCategory(): BelongsTo
    {
        return $this->belongsTo(self::class, 'canonical_category_id');
    }

    public function duplicateChildren(): HasMany
    {
        return $this->hasMany(self::class, 'canonical_category_id')->orderBy('sort_order')->orderBy('name');
    }

    public function activeChildren(): HasMany
    {
        return $this->children()->where('is_active', true);
    }

    public function supplierCategoryMappings(): HasMany
    {
        return $this->hasMany(SupplierCategoryMapping::class, 'standard_category_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(CategoryAlias::class, 'standard_category_id');
    }

    public function twinViews(): HasMany
    {
        return $this->hasMany(CategoryTwinView::class, 'canonical_category_id');
    }

    public function standardProducts(): HasMany
    {
        return $this->hasMany(StandardProduct::class, 'standard_category_id');
    }

    public function attributeRules(): HasMany
    {
        return $this->hasMany(CategoryAttributeRule::class, 'standard_category_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function attributeDefinitions(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductAttributeDefinition::class,
            'category_attribute_rules',
            'standard_category_id',
            'product_attribute_definition_id'
        )->withPivot(['is_required', 'is_filterable', 'visible_in_catalog', 'sort_order', 'meta'])->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where(function (Builder $builder) {
            $builder->where('code', 'like', 'ARCHIVED-%')
                ->orWhere('duplicate_status', 'archived')
                ->orWhere('meta->archived_by_category_reset', true);
        });
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('code', 'not like', 'ARCHIVED-%')
            ->where(function (Builder $builder) {
                $builder->whereNull('duplicate_status')
                    ->orWhere('duplicate_status', '!=', 'archived');
            })
            ->where(function (Builder $builder) {
                $builder->whereNull('meta->archived_by_category_reset')
                    ->orWhere('meta->archived_by_category_reset', false);
            });
    }

    public function scopePermanentBackbone(Builder $query): Builder
    {
        return $query->notArchived()
            ->where('is_active', true)
            ->where('visible_in_catalog', true)
            ->where(function (Builder $builder) {
                $builder->where('meta->permanent_category_backbone', true)
                    ->orWhere('meta->is_system', true)
                    ->orWhereNull('meta->supplier_dependent')
                    ->orWhere('meta->supplier_dependent', false);
            });
    }

    public function scopeForFamily(Builder $query, string $family): Builder
    {
        return $query->where('product_family', $family);
    }

    public function getFullPathAttribute(): string
    {
        return $this->path ?: $this->name;
    }

    public function updatePath(): void
    {
        $parent = $this->parent()->first();

        $this->depth = $parent ? $parent->depth + 1 : 0;
        $this->path = $parent ? trim($parent->path . ' / ' . $this->name, ' /') : $this->name;
        $this->slug = $this->slug ?: static::generateSlug($this->name);
        $this->saveQuietly();

        $this->children()->get()->each(function (StandardCategory $child) {
            $child->updatePath();
        });
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    public function isArchivedCategory(): bool
    {
        return str_starts_with((string) $this->code, 'ARCHIVED-')
            || $this->duplicate_status === 'archived'
            || (bool) data_get($this->meta, 'archived_by_category_reset', false);
    }

    public function isPermanentBackbone(): bool
    {
        return !$this->isArchivedCategory()
            && (bool) $this->is_active
            && (bool) $this->visible_in_catalog
            && data_get($this->meta, 'supplier_dependent') !== true;
    }

    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }

    public static function generateSlug(string $value): string
    {
        $map = [
            'Ç' => 'c', 'ç' => 'c',
            'Ğ' => 'g', 'ğ' => 'g',
            'İ' => 'i', 'I' => 'i', 'ı' => 'i',
            'Ö' => 'o', 'ö' => 'o',
            'Ş' => 's', 'ş' => 's',
            'Ü' => 'u', 'ü' => 'u',
        ];

        $normalized = strtr($value, $map);

        return Str::slug($normalized) ?: Str::slug(Str::lower($value), '-');
    }
}
