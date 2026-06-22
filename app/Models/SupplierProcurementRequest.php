<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierProcurementRequest extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'taslak';
    public const STATUS_REQUESTED = 'talep_edildi';
    public const STATUS_SUPPLIER_ORDERED = 'siparis_verildi';
    public const STATUS_PARTIALLY_RECEIVED = 'kismi_geldi';
    public const STATUS_COMPLETED = 'tamamlandi';
    public const STATUS_CANCELLED = 'iptal';

    protected $fillable = [
        'tenant_account_id',
        'supplier_id',
        'request_number',
        'request_date',
        'status',
        'note',
        'created_by',
        'updated_by',
        'cancelled_at',
    ];

    protected $casts = [
        'request_date' => 'date',
        'cancelled_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function account(): BelongsTo
    {
        return $this->tenant();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierProcurementRequestItem::class, 'supplier_procurement_request_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isRequested(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }

    public function isSupplierOrdered(): bool
    {
        return $this->status === self::STATUS_SUPPLIER_ORDERED;
    }

    public function isPartiallyReceived(): bool
    {
        return $this->status === self::STATUS_PARTIALLY_RECEIVED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function safeStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Taslak',
            self::STATUS_REQUESTED => 'Talep Edildi',
            self::STATUS_SUPPLIER_ORDERED => 'Sipariş Verildi',
            self::STATUS_PARTIALLY_RECEIVED => 'Kısmi Geldi',
            self::STATUS_COMPLETED => 'Tamamlandı',
            self::STATUS_CANCELLED => 'İptal',
            default => ucfirst(str_replace('_', ' ', (string) $this->status)),
        };
    }
}
