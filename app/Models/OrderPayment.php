<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPayment extends Model
{
    use HasFactory;

    public const TYPE_COLLECTION = 'tahsilat';
    public const TYPE_REFUND = 'iade';
    public const TYPE_ADJUSTMENT = 'duzeltme';

    public const METHOD_CASH = 'nakit';
    public const METHOD_BANK_TRANSFER = 'havale';
    public const METHOD_CREDIT_CARD = 'kredi_karti';
    public const METHOD_CHEQUE = 'cek';
    public const METHOD_PROMISSORY = 'senet';
    public const METHOD_OTHER = 'diger';

    protected $fillable = [
        'tenant_account_id',
        'order_id',
        'customer_company_id',
        'payment_type',
        'amount',
        'currency',
        'payment_method',
        'payment_note',
        'payment_reference',
        'paid_at',
        'due_date',
        'cancelled_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'due_date' => 'datetime',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'customer_company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isCollection(): bool
    {
        return $this->payment_type === self::TYPE_COLLECTION;
    }

    public function isRefund(): bool
    {
        return $this->payment_type === self::TYPE_REFUND;
    }

    public function isAdjustment(): bool
    {
        return $this->payment_type === self::TYPE_ADJUSTMENT;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function signedAmount(): float
    {
        if ($this->isCancelled()) {
            return 0.0;
        }

        $amount = round((float) $this->amount, 2);

        return match ($this->payment_type) {
            self::TYPE_REFUND => -abs($amount),
            self::TYPE_ADJUSTMENT => $amount,
            default => abs($amount),
        };
    }

    public function safePaymentTypeLabel(): string
    {
        return self::paymentTypeLabels()[$this->payment_type]
            ?? ucfirst(str_replace('_', ' ', (string) $this->payment_type));
    }

    public function safePaymentMethodLabel(): ?string
    {
        if (!$this->payment_method) {
            return null;
        }

        return self::paymentMethodLabels()[$this->payment_method]
            ?? ucfirst(str_replace('_', ' ', (string) $this->payment_method));
    }

    public static function paymentTypeLabels(): array
    {
        return [
            self::TYPE_COLLECTION => 'Tahsilat',
            self::TYPE_REFUND => 'İade',
            self::TYPE_ADJUSTMENT => 'Düzeltme',
        ];
    }

    public static function paymentMethodLabels(): array
    {
        return [
            self::METHOD_CASH => 'Nakit',
            self::METHOD_BANK_TRANSFER => 'Havale',
            self::METHOD_CREDIT_CARD => 'Kredi Kartı',
            self::METHOD_CHEQUE => 'Çek',
            self::METHOD_PROMISSORY => 'Senet',
            self::METHOD_OTHER => 'Diğer',
        ];
    }
}
