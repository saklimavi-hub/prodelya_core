<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_checkout_session_id',
        'payment_provider_id',
        'tenant_account_id',
        'transaction_type',
        'status',
        'amount',
        'currency',
        'external_reference',
        'gateway_reference',
        'provider_payload_json',
        'processed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'provider_payload_json' => 'array',
        'processed_at' => 'datetime',
    ];

    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(PaymentCheckoutSession::class, 'payment_checkout_session_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'payment_provider_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }
}
