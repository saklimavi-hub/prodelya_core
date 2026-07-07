<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
    public const SOURCE_TYPE_MANUAL = 'manual';

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
        return self::typeLabels()[$this->transaction_type] ?? 'Diğer Hareket';
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

    public function isManuallyCancellableFromStatement(): bool
    {
        if ($this->isCancelled()) {
            return false;
        }

        if ($this->source_type === self::SOURCE_TYPE_MANUAL) {
            return true;
        }

        if ($this->source_type === null || trim((string) $this->source_type) === '') {
            return !$this->source_id;
        }

        return false;
    }

    public function formattedAmount(): string
    {
        return number_format((float) $this->amount, 2, ',', '.') . ' ' . ($this->currency ?: 'TRY');
    }

    public function safeManualDocumentNumber(): ?string
    {
        return self::sanitizeMetaText(data_get($this->meta_json, 'manual.document_number'));
    }

    public function safeManualPaymentMethodLabel(): ?string
    {
        $method = (string) data_get($this->meta_json, 'manual.payment_method', '');

        if ($method === '') {
            return null;
        }

        return self::paymentMethodLabels()[$method] ?? 'Diğer';
    }

    public function safeManualOrderNumber(): ?string
    {
        return self::sanitizeMetaText(data_get($this->meta_json, 'manual.linked_order_number'));
    }

    public function safeManualOrderId(): ?int
    {
        $orderId = data_get($this->meta_json, 'manual.linked_order_id');

        return is_numeric($orderId) ? (int) $orderId : null;
    }

    public function safeManualInternalNote(): ?string
    {
        return self::sanitizeMetaText(data_get($this->meta_json, 'manual.internal_note'));
    }

    public static function typeLabels(): array
    {
        return [
            self::TYPE_CUSTOMER_DEBIT => 'Müşteri Borcu / Satış',
            self::TYPE_CUSTOMER_PAYMENT => 'Tahsilat',
            self::TYPE_SUPPLIER_DEBIT => 'Tedarikçi Borç',
            self::TYPE_SUPPLIER_PAYMENT => 'Tedarikçi Ödemesi',
            self::TYPE_SUBCONTRACTOR_DEBIT => 'Fason Borç',
            self::TYPE_SUBCONTRACTOR_PAYMENT => 'Fason Ödemesi',
            self::TYPE_CARRIER_DEBIT => 'Kargo / Teslimat Borcu',
            self::TYPE_CARRIER_PAYMENT => 'Kargo Ödemesi',
            self::TYPE_ADJUSTMENT => 'Mahsup / Düzeltme',
            self::TYPE_OPENING_BALANCE => 'Açılış Bakiyesi',
            self::TYPE_REFUND => 'İade / Düzeltme',
            self::TYPE_OTHER => 'Diğer Hareket',
        ];
    }

    public static function manualEntryTypeLabels(): array
    {
        return [
            self::TYPE_CUSTOMER_DEBIT => 'Müşteri Borç Fişi',
            self::TYPE_CUSTOMER_PAYMENT => 'Tahsilat',
            self::TYPE_REFUND => 'İade / Düzeltme',
            self::TYPE_SUPPLIER_DEBIT => 'Tedarikçi Borç Fişi',
            self::TYPE_SUPPLIER_PAYMENT => 'Tedarikçi Ödemesi',
            self::TYPE_SUBCONTRACTOR_DEBIT => 'Fason Borç Fişi',
            self::TYPE_SUBCONTRACTOR_PAYMENT => 'Fason Ödemesi',
            self::TYPE_CARRIER_DEBIT => 'Kargo / Teslimat Borcu',
            self::TYPE_CARRIER_PAYMENT => 'Kargo / Teslimat Ödemesi',
            self::TYPE_ADJUSTMENT => 'Mahsup / Düzeltme',
            self::TYPE_OPENING_BALANCE => 'Açılış Bakiyesi',
        ];
    }

    public static function paymentMethodLabels(): array
    {
        return \App\Models\OrderPayment::paymentMethodLabels();
    }

    public static function manualStatusLabels(): array
    {
        return [
            self::STATUS_OPEN => 'Açık',
            self::STATUS_PAID => 'Ödendi',
            self::STATUS_PARTIALLY_PAID => 'Kısmi Kapandı',
            self::STATUS_CLOSED => 'Kapalı / İşlendi',
        ];
    }

    public static function inferredDirectionForType(string $type, ?string $fallbackDirection = null): string
    {
        return match ($type) {
            self::TYPE_CUSTOMER_DEBIT,
            self::TYPE_SUPPLIER_DEBIT,
            self::TYPE_SUBCONTRACTOR_DEBIT,
            self::TYPE_CARRIER_DEBIT,
            self::TYPE_CUSTOMER_PAYMENT,
            self::TYPE_SUPPLIER_PAYMENT,
            self::TYPE_SUBCONTRACTOR_PAYMENT,
            self::TYPE_CARRIER_PAYMENT,
            self::TYPE_REFUND => self::typeDirectionMap()[$type],
            default => $fallbackDirection === self::DIRECTION_CREDIT ? self::DIRECTION_CREDIT : self::DIRECTION_DEBIT,
        };
    }

    public static function requiresManualDirection(string $type): bool
    {
        return in_array($type, [
            self::TYPE_ADJUSTMENT,
            self::TYPE_OPENING_BALANCE,
            self::TYPE_OTHER,
        ], true);
    }

    public static function typeDirectionMap(): array
    {
        return [
            self::TYPE_CUSTOMER_DEBIT => self::DIRECTION_DEBIT,
            self::TYPE_CUSTOMER_PAYMENT => self::DIRECTION_CREDIT,
            self::TYPE_SUPPLIER_DEBIT => self::DIRECTION_DEBIT,
            self::TYPE_SUPPLIER_PAYMENT => self::DIRECTION_CREDIT,
            self::TYPE_SUBCONTRACTOR_DEBIT => self::DIRECTION_DEBIT,
            self::TYPE_SUBCONTRACTOR_PAYMENT => self::DIRECTION_CREDIT,
            self::TYPE_CARRIER_DEBIT => self::DIRECTION_DEBIT,
            self::TYPE_CARRIER_PAYMENT => self::DIRECTION_CREDIT,
            self::TYPE_REFUND => self::DIRECTION_CREDIT,
        ];
    }

    public static function sanitizeMetaText(mixed $value): ?string
    {
        $text = trim(strip_tags((string) ($value ?? '')));

        return $text === '' ? null : Str::limit($text, 255, '');
    }

    public static function directionLabels(): array
    {
        return [
            self::DIRECTION_DEBIT => 'Borç',
            self::DIRECTION_CREDIT => 'Alacak',
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
