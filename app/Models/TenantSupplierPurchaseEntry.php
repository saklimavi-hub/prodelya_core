<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSupplierPurchaseEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'supplier_id',
        'supplier_source_id',
        'tenant_catalog_product_id',
        'tenant_catalog_product_variant_id',
        'stock_scope',
        'supplier_name',
        'product_code',
        'product_name',
        'quantity',
        'list_price',
        'discount_rate',
        'calculated_purchase_unit_price',
        'unit_purchase_price',
        'manual_purchase_unit_price',
        'currency',
        'original_currency',
        'exchange_rate',
        'exchange_rate_date',
        'original_list_price',
        'calculated_unit_price_original',
        'final_unit_price_original',
        'final_unit_price_try',
        'purchase_total_try',
        'manual_override',
        'vat_enabled',
        'vat_rate',
        'total_amount',
        'payable_amount',
        'entry_type',
        'entry_status',
        'payable_status',
        'idempotency_key',
        'document_no',
        'entry_date',
        'warehouse_code',
        'location_code',
        'notes',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'meta_json',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'list_price' => 'decimal:4',
        'discount_rate' => 'decimal:4',
        'calculated_purchase_unit_price' => 'decimal:4',
        'unit_purchase_price' => 'decimal:4',
        'manual_purchase_unit_price' => 'boolean',
        'exchange_rate' => 'decimal:6',
        'original_list_price' => 'decimal:4',
        'calculated_unit_price_original' => 'decimal:4',
        'final_unit_price_original' => 'decimal:4',
        'final_unit_price_try' => 'decimal:4',
        'purchase_total_try' => 'decimal:4',
        'manual_override' => 'boolean',
        'vat_enabled' => 'boolean',
        'vat_rate' => 'decimal:2',
        'total_amount' => 'decimal:4',
        'payable_amount' => 'decimal:4',
        'entry_date' => 'date',
        'exchange_rate_date' => 'date',
        'cancelled_at' => 'datetime',
        'meta_json' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function supplierSource(): BelongsTo
    {
        return $this->belongsTo(SupplierSource::class);
    }

    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(TenantCatalogProduct::class, 'tenant_catalog_product_id');
    }

    public function catalogVariant(): BelongsTo
    {
        return $this->belongsTo(TenantCatalogProductVariant::class, 'tenant_catalog_product_variant_id');
    }
}
