<?php

namespace App\Models;

use App\Services\ModuleFeatureCatalogService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageModule extends Model
{
    use HasFactory;

    protected $fillable = [
        'package_id',
        'module_key',
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
            $model->module_key = app(ModuleFeatureCatalogService::class)->normalizeModuleKey((string) $model->module_key);
        });
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
