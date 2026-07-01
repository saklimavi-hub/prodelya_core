<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class TenantBillingEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'tenant_service_definition_id',
        'package_key',
        'entry_type',
        'title',
        'note',
        'reference_no',
        'direction',
        'amount',
        'currency',
        'entry_date',
        'created_by',
        'updated_by',
        'meta_json',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'entry_date' => 'date',
        'meta_json' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function serviceDefinition(): BelongsTo
    {
        return $this->belongsTo(TenantServiceDefinition::class, 'tenant_service_definition_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function paymentCheckoutSessions(): MorphMany
    {
        return $this->morphMany(PaymentCheckoutSession::class, 'subject');
    }

    public function typeLabel(): string
    {
        return match ($this->entry_type) {
            'package_fee' => 'Paket Bedeli',
            'service_fee' => 'Hizmet Bedeli',
            'collection' => 'Tahsilat',
            'manual_debit' => 'Manuel Borç',
            'manual_credit' => 'Manuel Alacak',
            default => 'Kayıt',
        };
    }

    public function directionLabel(): string
    {
        return $this->direction === 'credit' ? 'Alacak' : 'Borç';
    }
}
