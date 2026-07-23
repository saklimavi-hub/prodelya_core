<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItemProcurement extends Model
{
    use HasFactory;

    public const FULFILLMENT_LOCAL_STOCK = 'local_stock';
    public const FULFILLMENT_SUPPLIER = 'supplier';
    public const FULFILLMENT_MIXED = 'mixed';
    public const FULFILLMENT_CUSTOMER_SUPPLIED = 'customer_supplied';
    public const FULFILLMENT_NOT_REQUIRED = 'not_required';

    public const STATUS_PENDING = 'tedarik_bekliyor';
    public const STATUS_REQUEST_CREATED = 'tedarik_talebi_acildi';
    public const STATUS_SUPPLIER_ORDERED = 'siparis_verildi';
    public const STATUS_PARTIALLY_RECEIVED = 'kismi_geldi';
    public const STATUS_FULLY_RECEIVED = 'tamami_geldi';
    public const STATUS_CANCELLED = 'iptal';
    public const STATUS_NOT_REQUIRED = 'tedarik_gerekmiyor';
    public const STATUS_CUSTOMER_WAITING = 'musteri_urunu_bekleniyor';
    public const STATUS_CUSTOMER_RECEIVED = 'musteri_urunu_geldi';

    protected $fillable = [
        'tenant_account_id',
        'order_id',
        'order_item_id',
        'work_form_id',
        'supplier_id',
        'supplier_source_id',
        'requires_procurement',
        'fulfillment_source',
        'procurement_status',
        'requested_quantity',
        'local_allocated_quantity',
        'supplier_requested_quantity',
        'received_quantity',
        'remaining_quantity',
        'snapshot',
        'procurement_snapshot',
        'notes',
        'ordered_at',
        'partially_received_at',
        'fully_received_at',
        'cancelled_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'requires_procurement' => 'boolean',
        'requested_quantity' => 'decimal:4',
        'local_allocated_quantity' => 'decimal:4',
        'supplier_requested_quantity' => 'decimal:4',
        'received_quantity' => 'decimal:4',
        'remaining_quantity' => 'decimal:4',
        'snapshot' => 'array',
        'procurement_snapshot' => 'array',
        'ordered_at' => 'datetime',
        'partially_received_at' => 'datetime',
        'fully_received_at' => 'datetime',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function supplierSource(): BelongsTo
    {
        return $this->belongsTo(SupplierSource::class, 'supplier_source_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function supplierRequestItems(): HasMany
    {
        return $this->hasMany(SupplierProcurementRequestItem::class, 'order_item_procurement_id');
    }

    public function isFullyReceived(): bool
    {
        return $this->procurement_status === self::STATUS_FULLY_RECEIVED
            || $this->remainingQuantity() <= 0.0;
    }

    public function isPartiallyReceived(): bool
    {
        return $this->procurement_status === self::STATUS_PARTIALLY_RECEIVED
            || ((float) $this->received_quantity > 0.0 && !$this->isFullyReceived());
    }

    public function isCustomerSupplied(): bool
    {
        return $this->fulfillment_source === self::FULFILLMENT_CUSTOMER_SUPPLIED;
    }

    public function isLocalStockBased(): bool
    {
        return $this->fulfillment_source === self::FULFILLMENT_LOCAL_STOCK;
    }

    public function isSupplierBased(): bool
    {
        return $this->fulfillment_source === self::FULFILLMENT_SUPPLIER;
    }

    public function isMixedSource(): bool
    {
        return $this->fulfillment_source === self::FULFILLMENT_MIXED;
    }

    public function isNotRequired(): bool
    {
        return $this->fulfillment_source === self::FULFILLMENT_NOT_REQUIRED
            || $this->procurement_status === self::STATUS_NOT_REQUIRED
            || !$this->requires_procurement;
    }

    public function remainingQuantity(): float
    {
        return (float) $this->remaining_quantity;
    }

    public function openSupplierRequest(): ?SupplierProcurementRequest
    {
        return $this->supplierRequestItems
            ->pluck('request')
            ->filter(fn (?SupplierProcurementRequest $request): bool => $request !== null && !$request->isCompleted() && !$request->isCancelled())
            ->sortByDesc('id')
            ->first();
    }

    public function userFacingState(): string
    {
        if ($this->procurement_status === self::STATUS_CANCELLED) {
            return 'cancelled';
        }

        if ($this->isNotRequired()) {
            return 'no_need';
        }

        if ($this->isFullyReceived()) {
            return 'received';
        }

        if ($this->isPartiallyReceived()) {
            return 'partial_received';
        }

        $request = $this->openSupplierRequest();

        if ($request?->isDraft()) {
            return 'request_draft';
        }

        if ($request !== null) {
            return 'request_sent';
        }

        return 'need_unrequested';
    }

    public function userFacingStatusLabel(): string
    {
        return match ($this->userFacingState()) {
            'no_need' => 'Tedarik Gerekli Değil',
            'need_unrequested' => 'Talep Hazırlanacak',
            'request_draft' => 'Talep Taslağı',
            'request_sent' => 'Tedarik Bekliyor',
            'partial_received' => 'Tedarik Kısmi Tamamlandı',
            'received' => 'Tedarik Tamamlandı',
            'cancelled' => 'İptal Edildi',
            default => self::statusLabels()[$this->procurement_status]
                ?? ucfirst(str_replace('_', ' ', (string) $this->procurement_status)),
        };
    }

    public function userFacingNextActionLabel(): ?string
    {
        return match ($this->userFacingState()) {
            'no_need', 'received' => null,
            'need_unrequested' => 'Tedarik talebini hazırla',
            'request_draft' => 'Talebi aç veya düzenle',
            'request_sent' => 'Tedarikçiden dönüş bekle',
            'partial_received' => 'Kalan ürünleri takip et',
            'cancelled' => 'Kontrol et',
            default => 'Tedarik kaydını incele',
        };
    }

    public function safeFulfillmentSourceLabel(): string
    {
        return self::fulfillmentSourceLabels()[$this->fulfillment_source]
            ?? ucfirst(str_replace('_', ' ', (string) $this->fulfillment_source));
    }

    public function safeStatusLabel(): string
    {
        return $this->userFacingStatusLabel();
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'Tedarik Bekliyor',
            self::STATUS_REQUEST_CREATED => 'Tedarik Talebi Açıldı',
            self::STATUS_SUPPLIER_ORDERED => 'Sipariş Verildi',
            self::STATUS_PARTIALLY_RECEIVED => 'Kısmi Geldi',
            self::STATUS_FULLY_RECEIVED => 'Tamamı Geldi',
            self::STATUS_CANCELLED => 'İptal',
            self::STATUS_NOT_REQUIRED => 'Tedarik Gerekli Değil',
            self::STATUS_CUSTOMER_WAITING => 'Müşteri Ürünü Bekleniyor',
            self::STATUS_CUSTOMER_RECEIVED => 'Müşteri Ürünü Geldi',
        ];
    }

    public static function fulfillmentSourceLabels(): array
    {
        return [
            self::FULFILLMENT_LOCAL_STOCK => 'Local Stok',
            self::FULFILLMENT_SUPPLIER => 'Tedarikçi',
            self::FULFILLMENT_MIXED => 'Kısmi Local + Tedarikçi',
            self::FULFILLMENT_CUSTOMER_SUPPLIED => 'Müşteri Ürünü',
            self::FULFILLMENT_NOT_REQUIRED => 'Tedarik Gerekmiyor',
        ];
    }
}
