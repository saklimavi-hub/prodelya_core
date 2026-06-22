<?php

namespace App\Models;

use App\Services\ModuleFeatureCatalogService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageFeature extends Model
{
    use HasFactory;

    protected $fillable = [
        'package_id',
        'module_key',
        'feature_key',
        'is_enabled',
        'status',
        'notes',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            $catalog = app(ModuleFeatureCatalogService::class);
            $model->feature_key = $catalog->normalizeFeatureKey((string) $model->feature_key);
            $model->module_key = filled($model->module_key)
                ? $catalog->normalizeModuleKey((string) $model->module_key)
                : null;
        });
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
