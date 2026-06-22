<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'status',
        'sort_order',
        'is_public',
        'trial_days',
        'monthly_price',
        'yearly_price',
        'currency',
        'notes',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'trial_days' => 'integer',
        'monthly_price' => 'decimal:2',
        'yearly_price' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function modules(): HasMany
    {
        return $this->hasMany(PackageModule::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(PackageFeature::class);
    }

    public function limits(): HasMany
    {
        return $this->hasMany(PackageLimit::class);
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(TenantAccount::class, 'package_key', 'key');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function moduleKeys(): array
    {
        return $this->modules()->where('is_enabled', true)->pluck('module_key')->all();
    }

    public function featureKeys(): array
    {
        return $this->features()->where('is_enabled', true)->pluck('feature_key')->all();
    }

    public function limitFor(string $key): ?PackageLimit
    {
        $this->loadMissing('limits');

        return $this->limits->firstWhere('limit_key', $key);
    }

    public function safeStatusLabel(): string
    {
        return match ($this->status) {
            'active' => 'Aktif',
            'passive' => 'Pasif',
            'planned' => 'Planlandı',
            'archived' => 'Arşiv',
            default => 'Bilinmiyor',
        };
    }

    public function formattedPrice(?string $interval = 'monthly'): ?string
    {
        $amount = $interval === 'yearly' ? $this->yearly_price : $this->monthly_price;

        if ($amount === null) {
            return null;
        }

        return number_format((float) $amount, 2, ',', '.') . ' ' . ($this->currency ?: 'TRY');
    }
}
