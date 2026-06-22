<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'order_id',
        'tenant_catalog_product_id',
        'tenant_catalog_product_variant_id',
        'standard_product_id',
        'standard_product_variant_id',
        'item_type',
        'product_source',
        'product_name',
        'product_code',
        'supplier_id',
        'supplier_source_id',
        'quantity',
        'unit',
        'description',
        'product_snapshot',
        'price_snapshot',
        'stock_snapshot',
        'catalog_source',
        'list_price',
        'discount_rate',
        'unit_price',
        'line_total',
        'has_print',
        'print_total',
        'status',
    ];

    protected $casts = [
        'item_type' => 'string',
        'product_source' => 'string',
        'product_snapshot' => 'array',
        'price_snapshot' => 'array',
        'stock_snapshot' => 'array',
        'catalog_source' => 'string',
        'quantity' => 'decimal:4',
        'list_price' => 'decimal:4',
        'discount_rate' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'line_total' => 'decimal:4',
        'has_print' => 'boolean',
        'print_total' => 'decimal:4',
        'status' => 'string',
    ];

    /**
     * Get the tenant that owns this order item
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the order that owns this item
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function tenantCatalogProduct(): BelongsTo
    {
        return $this->belongsTo(TenantCatalogProduct::class, 'tenant_catalog_product_id');
    }

    public function tenantCatalogProductVariant(): BelongsTo
    {
        return $this->belongsTo(TenantCatalogProductVariant::class, 'tenant_catalog_product_variant_id');
    }

    public function standardProduct(): BelongsTo
    {
        return $this->belongsTo(StandardProduct::class, 'standard_product_id');
    }

    public function standardProductVariant(): BelongsTo
    {
        return $this->belongsTo(StandardProductVariant::class, 'standard_product_variant_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function supplierSource(): BelongsTo
    {
        return $this->belongsTo(SupplierSource::class, 'supplier_source_id');
    }

    public function legacySupplierCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_id');
    }

    /**
     * Scope to get items by type
     */
    public function scopeByType($query, $type)
    {
        return $query->where('item_type', $type);
    }

    /**
     * Scope to get product items
     */
    public function scopeProducts($query)
    {
        return $query->where('item_type', 'product');
    }

    /**
     * Scope to get customer supplied products
     */
    public function scopeCustomerSupplied($query)
    {
        return $query->where('item_type', 'customer_supplied_product');
    }

    /**
     * Scope to get print services
     */
    public function scopePrintServices($query)
    {
        return $query->where('item_type', 'print_service');
    }

    /**
     * Scope to get items by source
     */
    public function scopeBySource($query, $source)
    {
        return $query->where('product_source', $source);
    }

    /**
     * Scope to get local stock items
     */
    public function scopeLocalStock($query)
    {
        return $query->where('product_source', 'local_stock');
    }

    /**
     * Scope to get supplier feed items
     */
    public function scopeSupplierFeed($query)
    {
        return $query->where('product_source', 'supplier_feed');
    }

    /**
     * Scope to get items by status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Get formatted quantity
     */
    public function getFormattedQuantityAttribute()
    {
        return number_format($this->quantity, 2, ',', '.') . ($this->unit ? ' ' . $this->unit : '');
    }

    /**
     * Check if this item is a product
     */
    public function isProduct()
    {
        return $this->item_type === 'product';
    }

    /**
     * Check if this item is a customer supplied product
     */
    public function isCustomerSupplied()
    {
        return $this->item_type === 'customer_supplied_product';
    }

    /**
     * Check if this item is a print service
     */
    public function isPrintService()
    {
        return $this->item_type === 'print_service';
    }

    /**
     * Check if this item is from local stock
     */
    public function isFromLocalStock()
    {
        return $this->product_source === 'local_stock';
    }

    /**
     * Check if this item is from supplier feed
     */
    public function isFromSupplierFeed()
    {
        return $this->product_source === 'supplier_feed';
    }

    /**
     * Get the prints for this order item
     */
    public function prints()
    {
        return $this->hasMany(OrderItemPrint::class);
    }

    public function workForm()
    {
        return $this->hasOne(OrderItemWorkForm::class);
    }

    public function procurement()
    {
        return $this->hasOne(OrderItemProcurement::class);
    }

    public function delivery()
    {
        return $this->hasOne(OrderItemWorkFormDelivery::class);
    }

    public function printProductions()
    {
        return $this->hasMany(OrderItemPrintProduction::class);
    }

    public function printGraphics()
    {
        return $this->hasMany(OrderItemPrintGraphic::class);
    }

    public function workFolders()
    {
        return $this->hasMany(OrderItemWorkFolder::class);
    }

    public function setupRequirements(): HasManyThrough
    {
        return $this->hasManyThrough(
            OrderItemPrintSetupRequirement::class,
            OrderItemPrint::class,
            'order_item_id',
            'order_item_print_id',
            'id',
            'id'
        );
    }

    /**
     * Get the total print cost for this item
     */
    public function printTotal()
    {
        return $this->prints()->sum('print_total');
    }

    /**
     * Calculate line total
     */
    public function calculateLineTotal()
    {
        return ($this->unit_price ?? 0) * $this->quantity;
    }

    /**
     * Get formatted unit price
     */
    public function getFormattedUnitPriceAttribute()
    {
        return number_format($this->unit_price ?? 0, 2, ',', '.') . ' TL';
    }

    /**
     * Get formatted line total
     */
    public function getFormattedLineTotalAttribute()
    {
        return number_format($this->line_total ?? 0, 2, ',', '.') . ' TL';
    }

    /**
     * Get formatted list price
     */
    public function getFormattedListPriceAttribute()
    {
        return number_format($this->list_price ?? 0, 2, ',', '.') . ' TL';
    }

    /**
     * Get formatted print total
     */
    public function getFormattedPrintTotalAttribute()
    {
        return number_format($this->print_total ?? 0, 2, ',', '.') . ' TL';
    }

    /**
     * Get effective price after discount
     */
    public function getEffectivePriceAttribute()
    {
        if ($this->list_price && $this->discount_rate) {
            return $this->list_price * (1 - ($this->discount_rate / 100));
        }
        
        return $this->unit_price ?? 0;
    }

    /**
     * Get formatted effective price
     */
    public function getFormattedEffectivePriceAttribute()
    {
        return number_format($this->effective_price, 2, ',', '.') . ' TL';
    }
}
