<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantServiceDefinition extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_code',
        'service_name',
        'category',
        'default_direction',
        'default_amount',
        'currency',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'default_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function billingEntries(): HasMany
    {
        return $this->hasMany(TenantBillingEntry::class);
    }

    public function directionLabel(): string
    {
        return $this->default_direction === 'credit' ? 'Alacak' : 'Borç';
    }

    public function statusLabel(): string
    {
        return $this->is_active ? 'Aktif' : 'Pasif';
    }
}
