<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrentAccountTransaction extends Model
{
    use HasFactory;

    public const TYPE_CUSTOMER_DEBIT = 'customer_debit';
    public const TYPE_CUSTOMER_PAYMENT = 'customer_payment';
    public const TYPE_SUPPLIER_DEBIT = 'supplier_debit';
    public const TYPE_SUPPLIER_PAYMENT = 'supplier_payment';
    public const TYPE_SUBCONTRACTOR_DEBIT = 'subcontractor_debit';
    public const TYPE_SUBCONTRACTOR_PAYMENT = 'subcontractor_payment';
    public const TYPE_CARRIER_DEBIT = 'carrier_debit';
    public const TYPE_CARRIER_PAYMENT = 'carrier_payment';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_OPENING_BALANCE = 'opening_balance';
    public const TYPE_REFUND = 'refund';
    public const TYPE_OTHER = 'other';

    public const DIRECTION_DEBIT = 'debit';
    public const DIRECTION_CREDIT = 'credit';

    public const STATUS_OPEN = 'open';
    public const STATUS_PAID = 'paid';
    public const STATUS_PARTIALLY_PAID = 'partially_paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'tenant_account_id',
        'current_account_id',
        'transaction_type',
        'source_type',
        'source_id',
        'direction',
        'amount',
        'currency',
        'transaction_date',
        'due_date',
        'description',
        'status',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'meta_json',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
        'due_date' => 'date',
        'cancelled_at' => 'datetime',
        'meta_json' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function account(): BelongsTo
    {
        return $this->tenant();
    }

    public function currentAccount(): BelongsTo
    {
        return $this->belongsTo(CurrentAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function safeTypeLabel(): string
    {
        return self::typeLabels()[$this->transaction_type] ?? ucfirst(str_replace('_', ' ', (string) $this->transaction_type));
    }

    public function safeDirectionLabel(): string
    {
        return self::directionLabels()[$this->direction] ?? ucfirst((string) $this->direction);
    }

    public function safeStatusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? ucfirst(str_replace('_', ' ', (string) $this->status));
    }

    public function isDebit(): bool
    {
        return $this->direction === self::DIRECTION_DEBIT;
    }

    public function isCredit(): bool
    {
        return $this->direction === self::DIRECTION_CREDIT;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED || $this->cancelled_at !== null;
    }

    public function formattedAmount(): string
    {
        return number_format((float) $this->amount, 2, ',', '.') . ' ' . ($this->currency ?: 'TRY');
    }

    public static function typeLabels(): array
    {
        return [
            self::TYPE_CUSTOMER_DEBIT => 'Müşteri Borç',
            self::TYPE_CUSTOMER_PAYMENT => 'Müşteri Tahsilat',
            self::TYPE_SUPPLIER_DEBIT => 'Tedarikçi Borç',
            self::TYPE_SUPPLIER_PAYMENT => 'Tedarikçi Ödeme',
            self::TYPE_SUBCONTRACTOR_DEBIT => 'Fason Borç',
            self::TYPE_SUBCONTRACTOR_PAYMENT => 'Fason Ödeme',
            self::TYPE_CARRIER_DEBIT => 'Kargo Borç',
            self::TYPE_CARRIER_PAYMENT => 'Kargo Ödeme',
            self::TYPE_ADJUSTMENT => 'Düzeltme',
            self::TYPE_OPENING_BALANCE => 'Açılış Bakiyesi',
            self::TYPE_REFUND => 'İade',
            self::TYPE_OTHER => 'Diğer',
        ];
    }

    public static function directionLabels(): array
    {
        return [
            self::DIRECTION_DEBIT => 'Borç',
            self::DIRECTION_CREDIT => 'Alacak / Ödeme',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_OPEN => 'Açık',
            self::STATUS_PAID => 'Ödendi',
            self::STATUS_PARTIALLY_PAID => 'Kısmi Kapandı',
            self::STATUS_CANCELLED => 'İptal',
            self::STATUS_CLOSED => 'Kapalı',
        ];
    }
}
