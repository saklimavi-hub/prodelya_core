<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhookLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_provider_id',
        'tenant_account_id',
        'scope_type',
        'event_key',
        'status',
        'external_reference',
        'headers_json',
        'payload_json',
        'notes',
        'processed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'headers_json' => 'array',
        'payload_json' => 'array',
        'processed_at' => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'payment_provider_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }
}
