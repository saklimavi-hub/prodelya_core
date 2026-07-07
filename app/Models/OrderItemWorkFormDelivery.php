<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemWorkFormDelivery extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'teslimat_bekliyor';
    public const STATUS_PREPARING = 'teslimata_hazirlaniyor';
    public const STATUS_READY = 'teslimata_hazir';
    public const STATUS_SHIPPED = 'kargoya_verildi';
    public const STATUS_COURIER_OUT = 'kurye_teslimatta';
    public const STATUS_PARTIALLY_DELIVERED = 'kismi_teslim_edildi';
    public const STATUS_DELIVERED = 'teslim_edildi';
    public const STATUS_ISSUE = 'teslimat_sorunu';
    public const STATUS_CANCELLED = 'iptal';

    public const METHOD_CARGO = 'kargo';
    public const METHOD_COURIER = 'kurye';
    public const METHOD_HAND = 'elden';
    public const METHOD_FREIGHT = 'ambar';
    public const METHOD_CUSTOMER_PICKUP = 'musteri_alacak';
    public const METHOD_OTHER = 'diger';

    public const PACKAGE_BOX = 'koli';
    public const PACKAGE_BAG = 'poset';
    public const PACKAGE_CASE = 'kutu';
    public const PACKAGE_PALLET = 'palet';
    public const PACKAGE_MIXED = 'karisik';
    public const PACKAGE_OTHER = 'diger';

    public const WARNING_PAYMENT_PENDING = 'odeme_bekliyor';
    public const WARNING_BALANCE_DUE = 'bakiye_var';
    public const WARNING_CHECK_BEFORE_DELIVERY = 'teslimat_oncesi_kontrol';
    public const WARNING_COLLECTION_APPROVAL = 'tahsilat_onayi_bekleniyor';
    public const WARNING_NONE = 'yok';

    protected $fillable = [
        'tenant_account_id',
        'order_id',
        'order_item_id',
        'work_form_id',
        'delivery_status',
        'delivery_method',
        'planned_quantity',
        'delivered_quantity',
        'remaining_quantity',
        'package_count',
        'units_per_package',
        'packaged_quantity',
        'package_type',
        'package_note',
        'carrier_name',
        'tracking_number',
        'recipient_name',
        'delivery_document_no',
        'recipient_phone',
        'delivery_note',
        'financial_warning',
        'delivery_snapshot',
        'prepared_at',
        'ready_at',
        'shipped_at',
        'partially_delivered_at',
        'delivered_at',
        'issue_reported_at',
        'cancelled_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'planned_quantity' => 'decimal:4',
        'delivered_quantity' => 'decimal:4',
        'remaining_quantity' => 'decimal:4',
        'package_count' => 'integer',
        'units_per_package' => 'integer',
        'packaged_quantity' => 'integer',
        'delivery_snapshot' => 'array',
        'prepared_at' => 'datetime',
        'ready_at' => 'datetime',
        'shipped_at' => 'datetime',
        'partially_delivered_at' => 'datetime',
        'delivered_at' => 'datetime',
        'issue_reported_at' => 'datetime',
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

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function workForm(): BelongsTo
    {
        return $this->belongsTo(OrderItemWorkForm::class, 'work_form_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isDelivered(): bool
    {
        return $this->delivery_status === self::STATUS_DELIVERED
            || $this->remainingQuantity() <= 0.0;
    }

    public function isPartiallyDelivered(): bool
    {
        return $this->delivery_status === self::STATUS_PARTIALLY_DELIVERED
            || ((float) $this->delivered_quantity > 0.0 && !$this->isDelivered());
    }

    public function hasIssue(): bool
    {
        return $this->delivery_status === self::STATUS_ISSUE;
    }

    public function remainingQuantity(): float
    {
        return (float) $this->remaining_quantity;
    }

    public function safeStatusLabel(): string
    {
        return self::statusLabels()[$this->delivery_status]
            ?? ucfirst(str_replace('_', ' ', (string) $this->delivery_status));
    }

    public function safeDeliveryMethodLabel(): ?string
    {
        if (!$this->delivery_method) {
            return null;
        }

        return self::deliveryMethodLabels()[$this->delivery_method]
            ?? ucfirst(str_replace('_', ' ', (string) $this->delivery_method));
    }

    public function safeFinancialWarningLabel(): string
    {
        return self::financialWarningLabels()[$this->financial_warning ?: self::WARNING_NONE]
            ?? 'Finans uyarısı yok';
    }

    public function publicStatusLabel(): string
    {
        return self::publicStatusLabels()[$this->delivery_status]
            ?? 'Teslimat bekliyor';
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'Teslimat Bekliyor',
            self::STATUS_PREPARING => 'Teslimata Hazırlanıyor',
            self::STATUS_READY => 'Teslimata Hazır',
            self::STATUS_SHIPPED => 'Kargoya Verildi',
            self::STATUS_COURIER_OUT => 'Kurye Teslimatta',
            self::STATUS_PARTIALLY_DELIVERED => 'Kısmi Teslim Edildi',
            self::STATUS_DELIVERED => 'Teslim Edildi',
            self::STATUS_ISSUE => 'Teslimat Sorunu',
            self::STATUS_CANCELLED => 'İptal',
        ];
    }

    public static function deliveryMethodLabels(): array
    {
        return [
            self::METHOD_CARGO => 'Kargo',
            self::METHOD_COURIER => 'Kurye',
            self::METHOD_HAND => 'Elden Teslim',
            self::METHOD_FREIGHT => 'Ambar',
            self::METHOD_CUSTOMER_PICKUP => 'Müşteri Kendisi Alacak',
            self::METHOD_OTHER => 'Diğer',
        ];
    }

    public static function financialWarningLabels(): array
    {
        return [
            self::WARNING_PAYMENT_PENDING => 'Ödeme bekliyor',
            self::WARNING_BALANCE_DUE => 'Bakiye var',
            self::WARNING_CHECK_BEFORE_DELIVERY => 'Teslimat öncesi ödeme kontrolü gerekli',
            self::WARNING_COLLECTION_APPROVAL => 'Tahsilat onayı bekleniyor',
            self::WARNING_NONE => 'Finans uyarısı yok',
        ];
    }

    public static function publicStatusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'Teslimat bekliyor',
            self::STATUS_PREPARING => 'Teslimata hazırlanıyor',
            self::STATUS_READY => 'Teslimata hazır',
            self::STATUS_SHIPPED => 'Kargoya verildi',
            self::STATUS_COURIER_OUT => 'Kurye teslimatta',
            self::STATUS_PARTIALLY_DELIVERED => 'Kısmi teslim edildi',
            self::STATUS_DELIVERED => 'Teslim edildi',
            self::STATUS_ISSUE => 'Teslimat süreci kontrol ediliyor',
            self::STATUS_CANCELLED => 'Teslimat süreci durduruldu',
        ];
    }

    public static function packageTypeLabels(): array
    {
        return [
            self::PACKAGE_BOX => 'Koli',
            self::PACKAGE_BAG => 'Poşet',
            self::PACKAGE_CASE => 'Kutu',
            self::PACKAGE_PALLET => 'Palet',
            self::PACKAGE_MIXED => 'Karışık',
            self::PACKAGE_OTHER => 'Diğer',
        ];
    }
}
