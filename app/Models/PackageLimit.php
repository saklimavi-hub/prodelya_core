<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageLimit extends Model
{
    use HasFactory;

    protected $fillable = [
        'package_id',
        'limit_key',
        'limit_value',
        'is_unlimited',
        'notes',
    ];

    protected $casts = [
        'limit_value' => 'integer',
        'is_unlimited' => 'boolean',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function isUnlimited(): bool
    {
        return $this->is_unlimited === true;
    }

    public function effectiveLimitValue(): ?int
    {
        return $this->isUnlimited() ? null : $this->limit_value;
    }
}
