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
        'vat_enabled',
        'vat_rate',
        'total_amount',
        'payable_amount',
        'entry_type',
        'payable_status',
        'document_no',
        'entry_date',
        'warehouse_code',
        'location_code',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'list_price' => 'decimal:4',
        'discount_rate' => 'decimal:4',
        'calculated_purchase_unit_price' => 'decimal:4',
        'unit_purchase_price' => 'decimal:4',
        'manual_purchase_unit_price' => 'boolean',
        'vat_enabled' => 'boolean',
        'vat_rate' => 'decimal:2',
        'total_amount' => 'decimal:4',
        'payable_amount' => 'decimal:4',
        'entry_date' => 'date',
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
}
