<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProcurementRequestItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'supplier_procurement_request_id',
        'order_item_procurement_id',
        'order_id',
        'order_item_id',
        'work_form_id',
        'supplier_source_id',
        'product_code',
        'product_name',
        'requested_quantity',
        'unit',
        'received_quantity',
        'remaining_quantity',
        'purchase_source_amount',
        'purchase_source_currency',
        'purchase_fx_rate',
        'purchase_fx_rate_date',
        'purchase_fx_rate_source',
        'purchase_list_price',
        'purchase_list_price_try',
        'purchase_calculated_unit_price',
        'purchase_manual_unit_price',
        'purchase_manual_override',
        'purchase_manual_override_reason',
        'purchase_settlement_currency',
        'purchase_price_snapshot',
        'purchase_price_snapshot_version',
        'discount_rate',
        'purchase_unit_price',
        'purchase_total',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'requested_quantity' => 'decimal:2',
        'received_quantity' => 'decimal:2',
        'remaining_quantity' => 'decimal:2',
        'purchase_source_amount' => 'decimal:6',
        'purchase_source_currency' => 'string',
        'purchase_fx_rate' => 'decimal:8',
        'purchase_fx_rate_date' => 'immutable_datetime',
        'purchase_fx_rate_source' => 'string',
        'purchase_list_price' => 'decimal:2',
        'purchase_list_price_try' => 'decimal:6',
        'purchase_calculated_unit_price' => 'decimal:6',
        'purchase_manual_unit_price' => 'decimal:6',
        'purchase_manual_override' => 'boolean',
        'purchase_manual_override_reason' => 'string',
        'purchase_settlement_currency' => 'string',
        'purchase_price_snapshot' => 'array',
        'purchase_price_snapshot_version' => 'integer',
        'discount_rate' => 'decimal:2',
        'purchase_unit_price' => 'decimal:2',
        'purchase_total' => 'decimal:2',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function account(): BelongsTo
    {
        return $this->tenant();
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SupplierProcurementRequest::class, 'supplier_procurement_request_id');
    }

    public function procurement(): BelongsTo
    {
        return $this->belongsTo(OrderItemProcurement::class, 'order_item_procurement_id');
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

    public function recalculatePurchaseTotals(?float $manualUnitPrice = null, ?float $quantityOverride = null): static
    {
        $listPrice = $this->purchase_list_price !== null ? (float) $this->purchase_list_price : null;
        $discountRate = $this->discount_rate !== null ? (float) $this->discount_rate : 0.0;
        $calculationQuantity = $quantityOverride ?? (float) $this->requested_quantity;

        if ($manualUnitPrice !== null) {
            $unitPrice = round($manualUnitPrice, 2);
            $this->purchase_unit_price = $unitPrice;
            $this->purchase_total = round($unitPrice * $calculationQuantity, 2);

            return $this;
        }

        if ($listPrice === null) {
            if ($this->purchase_unit_price !== null) {
                $this->purchase_total = round((float) $this->purchase_unit_price * $calculationQuantity, 2);
            } else {
                $this->purchase_unit_price = null;
                $this->purchase_total = null;
            }

            return $this;
        }

        $unitPrice = round($listPrice * (1 - ($discountRate / 100)), 2);
        $this->purchase_unit_price = $unitPrice;
        $this->purchase_total = round($unitPrice * $calculationQuantity, 2);

        return $this;
    }

    public function recalculateRemainingQuantity(): static
    {
        $remaining = round((float) $this->requested_quantity - (float) $this->received_quantity, 2);
        $this->remaining_quantity = max($remaining, 0);

        return $this;
    }

    public function hasPurchasePrice(): bool
    {
        return $this->purchase_list_price !== null
            || $this->purchase_unit_price !== null
            || $this->purchase_total !== null;
    }

    public function hasCanonicalPurchaseSnapshot(): bool
    {
        return is_array($this->purchase_price_snapshot)
            && !empty($this->purchase_price_snapshot)
            && (int) ($this->purchase_price_snapshot_version ?? 0) >= 1;
    }

    public function isPurchasePriceManualOverride(): bool
    {
        return (bool) ($this->purchase_manual_override ?? false);
    }

    public function finalPurchaseUnitPrice(): ?string
    {
        return $this->purchase_manual_override && $this->purchase_manual_unit_price !== null
            ? (string) $this->purchase_manual_unit_price
            : ($this->purchase_calculated_unit_price !== null
                ? (string) $this->purchase_calculated_unit_price
                : ($this->purchase_unit_price !== null ? (string) $this->purchase_unit_price : null));
    }

    public function safeUnitLabel(): string
    {
        $unit = trim((string) ($this->unit ?? ''));

        return $unit !== '' ? $unit : 'Adet';
    }
}
