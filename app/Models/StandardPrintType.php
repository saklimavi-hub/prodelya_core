<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StandardPrintType extends Model
{
    use HasFactory;

    public const MODE_INTERNAL = 'internal';
    public const MODE_OUTSOURCED = 'outsourced';
    public const MODE_BOTH = 'both';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PASSIVE = 'passive';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'code',
        'name',
        'production_family',
        'default_requires_graphic',
        'default_requires_production',
        'default_requires_setup',
        'default_setup_types',
        'default_production_mode',
        'status',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'default_requires_graphic' => 'boolean',
        'default_requires_production' => 'boolean',
        'default_requires_setup' => 'boolean',
        'default_setup_types' => 'array',
        'sort_order' => 'integer',
    ];

    public function tenantSettings(): HasMany
    {
        return $this->hasMany(TenantPrintSetting::class);
    }

    public function tenantPrintOptions(): HasMany
    {
        return $this->hasMany(TenantPrintOption::class);
    }

    public function safeName(): string
    {
        return trim((string) ($this->name ?: $this->code ?: ('Baskı Tipi #' . $this->id)));
    }

    public function safeCode(): string
    {
        return trim((string) ($this->code ?: 'PRINT_TYPE_' . $this->id));
    }

    public function safeStatusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? ucfirst(str_replace('_', ' ', (string) $this->status));
    }

    public function safeProductionModeLabel(): string
    {
        return self::productionModeLabels()[$this->default_production_mode]
            ?? ucfirst(str_replace('_', ' ', (string) $this->default_production_mode));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public static function productionModeLabels(): array
    {
        return [
            self::MODE_INTERNAL => 'İç Üretim',
            self::MODE_OUTSOURCED => 'Fason / Dış Üretim',
            self::MODE_BOTH => 'İç + Fason',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_ACTIVE => 'Aktif',
            self::STATUS_PASSIVE => 'Pasif',
            self::STATUS_ARCHIVED => 'Arşiv',
        ];
    }

    public static function setupTypeLabels(): array
    {
        return [
            'cliche' => 'Klişe',
            'mold' => 'Kalıp',
            'film' => 'Film',
            'montage' => 'Montaj',
            'die_cut' => 'Bıçak / Kesim',
            'stencil' => 'Şablon',
            'apparatus' => 'Aparat',
            'color_separation' => 'Renk Ayrımı',
            'foil_mold' => 'Varak / Lak Kalıbı',
            'laser_template' => 'Lazer Şablonu',
            'other' => 'Diğer',
        ];
    }
}
