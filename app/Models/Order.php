<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    public const COPY_TYPE_REVISION = 'revision';
    public const COPY_TYPE_REPEAT_ORDER = 'repeat_order';

    public const CUSTOMER_APPROVAL_NOT_SENT = 'not_sent';
    public const CUSTOMER_APPROVAL_WAITING = 'waiting';
    public const CUSTOMER_APPROVAL_APPROVED = 'approved';
    public const CUSTOMER_APPROVAL_REVISION_REQUESTED = 'revision_requested';
    public const CUSTOMER_APPROVAL_REJECTED = 'rejected';
    public const CUSTOMER_APPROVAL_EXPIRED = 'expired';
    public const CUSTOMER_APPROVAL_CANCELLED = 'cancelled';

    public const CUSTOMER_APPROVAL_SOURCE_INTERNAL_MANUAL = 'internal_manual';
    public const CUSTOMER_APPROVAL_SOURCE_CUSTOMER_PUBLIC_LINK = 'customer_public_link';
    public const CUSTOMER_APPROVAL_SOURCE_PHONE_WHATSAPP = 'phone_whatsapp';
    public const CUSTOMER_APPROVAL_SOURCE_CUSTOMER_PORTAL = 'customer_portal';

    protected $fillable = [
        'tenant_account_id',
        'order_family',
        'order_mode',
        'document_type',
        'document_number',
        'source_quote_id',
        'source_quote_number',
        'source_order_id',
        'copy_type',
        'revision_number',
        'copied_by_user_id',
        'copied_at',
        'customer_company_id',
        'status',
        'workflow_status',
        'customer_approval_status',
        'customer_approval_source',
        'quote_date',
        'valid_until',
        'invoice_status',
        'delivery_type',
        'delivery_type_id',
        'show_print_price_details_to_customer',
        'notes',
        'currency',
        'subtotal',
        'vat_total',
        'grand_total',
        'product_total',
        'print_total',
        'vat_breakdown_json',
        'created_by',
        'last_sent_at',
        'approved_at',
        'rejected_at',
        'revision_requested_at',
    ];

    protected $casts = [
        'order_family' => 'string',
        'order_mode' => 'string',
        'document_type' => 'string',
        'copy_type' => 'string',
        'status' => 'string',
        'revision_number' => 'integer',
        'customer_approval_status' => 'string',
        'customer_approval_source' => 'string',
        'quote_date' => 'date',
        'valid_until' => 'date',
        'invoice_status' => 'string',
        'delivery_type' => 'string',
        'delivery_type_id' => 'integer',
        'show_print_price_details_to_customer' => 'boolean',
        'last_sent_at' => 'datetime',
        'copied_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'revision_requested_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'vat_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'product_total' => 'decimal:2',
        'print_total' => 'decimal:2',
        'vat_breakdown_json' => 'array',
    ];

    // TODO: Add relationships with items, customer, source quote, etc.
    // TODO: Add scope methods for different order types and statuses
    // TODO: Add validation for order families and modes
    // TODO: Add financial visibility policies
    
    /**
     * Get the tenant that owns this order
     */
    public function tenant()
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the items for this order
     */
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function workForms()
    {
        return $this->hasMany(OrderItemWorkForm::class);
    }

    public function procurements()
    {
        return $this->hasMany(OrderItemProcurement::class);
    }

    public function printProductions()
    {
        return $this->hasMany(OrderItemPrintProduction::class);
    }

    public function printGraphics()
    {
        return $this->hasMany(OrderItemPrintGraphic::class);
    }

    public function deliveries()
    {
        return $this->hasMany(OrderItemWorkFormDelivery::class);
    }

    public function deliveryPackages()
    {
        return $this->hasMany(OrderDeliveryPackage::class);
    }

    public function deliveryLabelBatches()
    {
        return $this->hasMany(OrderDeliveryLabelBatch::class);
    }

    public function payments()
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function setupRequirements()
    {
        return $this->hasMany(OrderItemPrintSetupRequirement::class);
    }

    public function workFolders()
    {
        return $this->hasMany(OrderItemWorkFolder::class);
    }

    /**
     * Get the customer company for this order
     */
    public function customer()
    {
        return $this->belongsTo(Company::class, 'customer_company_id');
    }

    public function deliveryTypeSetting()
    {
        return $this->belongsTo(TenantDeliveryType::class, 'delivery_type_id');
    }

    public function shouldShowPrintPriceDetailsToCustomer(): bool
    {
        return $this->show_print_price_details_to_customer === null
            ? true
            : (bool) $this->show_print_price_details_to_customer;
    }

    /**
     * Get the source quote if this order was created from a quote
     */
    public function sourceQuote()
    {
        return $this->belongsTo(Order::class, 'source_quote_id');
    }

    /**
     * Get the orders created from this quote
     */
    public function convertedOrders()
    {
        return $this->hasMany(Order::class, 'source_quote_id');
    }

    public function sourceOrder()
    {
        return $this->belongsTo(Order::class, 'source_order_id');
    }

    public function copiedQuoteDrafts()
    {
        return $this->hasMany(Order::class, 'source_order_id');
    }

    public function quoteSendSnapshots(): HasMany
    {
        return $this->hasMany(QuoteSendSnapshot::class, 'quote_id');
    }

    public function quoteApprovalRequests(): HasMany
    {
        return $this->hasMany(QuoteApprovalRequest::class, 'quote_id');
    }

    public function graphicApprovalRequests(): HasMany
    {
        return $this->hasMany(GraphicApprovalRequest::class, 'order_id');
    }

    public function latestQuoteSendSnapshot(): HasOne
    {
        return $this->hasOne(QuoteSendSnapshot::class, 'quote_id')->latestOfMany('send_no');
    }

    public function latestQuoteApprovalRequest(): HasOne
    {
        return $this->hasOne(QuoteApprovalRequest::class, 'quote_id')->latestOfMany();
    }

    /**
     * Get the user who created this order
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function copiedByUser()
    {
        return $this->belongsTo(User::class, 'copied_by_user_id');
    }

    /**
     * Scope to get only quotes
     */
    public function scopeQuotes($query)
    {
        return $query->where('document_type', 'quote');
    }

    /**
     * Scope to get only orders
     */
    public function scopeOrders($query)
    {
        return $query->where('document_type', 'order');
    }

    /**
     * Scope to get promotion orders
     */
    public function scopePromotion($query)
    {
        return $query->where('order_family', 'promotion');
    }

    /**
     * Scope to get print orders
     */
    public function scopePrint($query)
    {
        return $query->where('order_family', 'print');
    }

    /**
     * Scope to get orders by status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get active orders (not cancelled or completed)
     */
    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['cancelled', 'completed']);
    }

    /**
     * Check if user can view financial data for this order
     */
    public function canUserViewFinancialData($user)
    {
        return $user->hasPermissionInTenant('view_order_finance_summary', $this->tenant_account_id) ||
               $user->hasPermissionInTenant('view_sales_prices', $this->tenant_account_id) ||
               $user->hasPermissionInTenant('view_quote_totals', $this->tenant_account_id) ||
               $user->hasPermissionInTenant('view_profit_margin', $this->tenant_account_id);
    }

    /**
     * Get formatted totals with currency
     */
    public function getFormattedSubtotalAttribute()
    {
        return number_format($this->subtotal, 2, ',', '.') . ' ' . $this->currency;
    }

    public function getFormattedVatTotalAttribute()
    {
        return number_format($this->vat_total, 2, ',', '.') . ' ' . $this->currency;
    }

    public function getFormattedGrandTotalAttribute()
    {
        return number_format($this->grand_total, 2, ',', '.') . ' ' . $this->currency;
    }

    /**
     * Check if this is a quote
     */
    public function isQuote()
    {
        return $this->document_type === 'quote';
    }

    /**
     * Check if this is a promotion order
     */
    public function isPromotion()
    {
        return $this->order_family === 'promotion';
    }

    /**
     * Check if this order can be edited
     */
    public function canBeEdited()
    {
        return !(
            $this->isQuote() && (
                $this->workflow_status === 'quote_converted' ||
                $this->convertedOrders()->exists()
            )
        );
    }

    public function isRevisionDraft(): bool
    {
        return $this->isQuote() && $this->copy_type === self::COPY_TYPE_REVISION;
    }

    public function isRepeatOrderDraft(): bool
    {
        return $this->isQuote() && $this->copy_type === self::COPY_TYPE_REPEAT_ORDER;
    }

    public function copyTypeLabel(): ?string
    {
        if ($this->isRevisionDraft()) {
            return 'Revize ' . max(1, (int) $this->revision_number);
        }

        if ($this->isRepeatOrderDraft()) {
            return 'Tekrar Sipariş';
        }

        return null;
    }

    public function copyTypeWarning(): ?string
    {
        if ($this->isRevisionDraft()) {
            return 'Bu kayıt sipariş revizyon taslağıdır. Orijinal sipariş doğrudan değiştirilmez.';
        }

        if ($this->isRepeatOrderDraft()) {
            return 'Bu kayıt eski siparişten oluşturulan yeni teklif taslağıdır. Eski siparişin operasyon ve finans geçmişi kopyalanmaz.';
        }

        return null;
    }

    public function safeCustomerApprovalStatusLabel(): string
    {
        return self::customerApprovalStatusLabels()[$this->customer_approval_status ?: self::CUSTOMER_APPROVAL_NOT_SENT]
            ?? ucfirst(str_replace('_', ' ', (string) $this->customer_approval_status));
    }

    public function quoteDisplayStatusLabel(): string
    {
        if ($this->workflow_status === 'quote_converted') {
            return 'Siparişe Dönüştü';
        }

        if (! $this->isQuote()) {
            return ucfirst((string) $this->status);
        }

        $approvalStatus = $this->customer_approval_status ?: self::CUSTOMER_APPROVAL_NOT_SENT;

        return match ($approvalStatus) {
            self::CUSTOMER_APPROVAL_WAITING => 'Onay Bekliyor',
            self::CUSTOMER_APPROVAL_REVISION_REQUESTED => 'Revize İstendi',
            self::CUSTOMER_APPROVAL_APPROVED => 'Onaylandı',
            self::CUSTOMER_APPROVAL_REJECTED => 'Reddedildi',
            self::CUSTOMER_APPROVAL_EXPIRED => 'Süresi Doldu',
            default => 'Teklif',
        };
    }

    public function displayQuoteStatusLabel(): string
    {
        return $this->quoteDisplayStatusLabel();
    }

    public static function customerApprovalStatusLabels(): array
    {
        return [
            self::CUSTOMER_APPROVAL_NOT_SENT => 'Teklif',
            self::CUSTOMER_APPROVAL_WAITING => 'Onay Bekliyor',
            self::CUSTOMER_APPROVAL_APPROVED => 'Onaylandı',
            self::CUSTOMER_APPROVAL_REVISION_REQUESTED => 'Revize İstendi',
            self::CUSTOMER_APPROVAL_REJECTED => 'Reddedildi',
            self::CUSTOMER_APPROVAL_EXPIRED => 'Süresi Doldu',
            self::CUSTOMER_APPROVAL_CANCELLED => 'İptal',
        ];
    }

    /**
     * Recalculate totals from items
     */
    public function recalculateTotals()
    {
        $subtotal = 0;
        $vatTotal = 0;

        foreach ($this->items as $item) {
            $subtotal += $item->calculateLineTotal();
            $subtotal += (float) $item->prints()->sum('print_total');
            $vatTotal += (float) data_get($item->price_snapshot, 'line_vat_total', 0);
        }

        $this->subtotal = $subtotal;
        $this->vat_total = $vatTotal;
        $this->grand_total = $this->subtotal + $this->vat_total;

        $this->save();
    }
}
