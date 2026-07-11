<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItemPrint extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'order_id',
        'order_item_id',
        'tenant_print_setting_id',
        'standard_print_type_id',
        'tenant_print_option_id',
        'print_type',
        'print_option',
        'print_location',
        'production_type',
        'subcontractor_company_id',
        'print_color',
        'print_size',
        'cliche_status',
        'setup_pricing_enabled',
        'setup_type',
        'setup_status',
        'setup_total_amount',
        'setup_distribution_quantity',
        'setup_unit_amount',
        'base_print_unit_price',
        'print_quantity',
        'print_unit_price',
        'print_total',
        'pricing_snapshot',
        'note',
        'production_note',
        'status',
    ];

    protected $casts = [
        'print_quantity' => 'decimal:4',
        'setup_pricing_enabled' => 'boolean',
        'setup_total_amount' => 'decimal:4',
        'setup_distribution_quantity' => 'decimal:4',
        'setup_unit_amount' => 'decimal:4',
        'base_print_unit_price' => 'decimal:4',
        'print_unit_price' => 'decimal:4',
        'print_total' => 'decimal:4',
        'pricing_snapshot' => 'array',
        'status' => 'string',
    ];

    /**
     * Get the tenant that owns this order item print
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the order that owns this order item print
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the order item that owns this print
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function subcontractorCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'subcontractor_company_id');
    }

    public function tenantPrintSetting(): BelongsTo
    {
        return $this->belongsTo(TenantPrintSetting::class, 'tenant_print_setting_id');
    }

    public function standardPrintType(): BelongsTo
    {
        return $this->belongsTo(StandardPrintType::class, 'standard_print_type_id');
    }

    public function tenantPrintOption(): BelongsTo
    {
        return $this->belongsTo(TenantPrintOption::class, 'tenant_print_option_id');
    }

    public function production(): HasOne
    {
        return $this->hasOne(OrderItemPrintProduction::class, 'order_item_print_id');
    }

    public function printProduction(): HasOne
    {
        return $this->production();
    }

    public function graphicOperation(): HasOne
    {
        return $this->hasOne(OrderItemPrintGraphic::class, 'order_item_print_id');
    }

    public function graphic(): HasOne
    {
        return $this->graphicOperation();
    }

    public function setupRequirements(): HasMany
    {
        return $this->hasMany(OrderItemPrintSetupRequirement::class, 'order_item_print_id');
    }

    public function pendingSetupRequirements(): HasMany
    {
        return $this->setupRequirements()->whereIn('status', [
            OrderItemPrintSetupRequirement::STATUS_PENDING,
            OrderItemPrintSetupRequirement::STATUS_REQUESTED,
        ]);
    }

    public function readySetupRequirements(): HasMany
    {
        return $this->setupRequirements()->where('status', OrderItemPrintSetupRequirement::STATUS_READY);
    }

    /**
     * Scope to get draft prints
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope to get pending prints
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to get approved prints
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Check if print is approved
     */
    public function isApproved()
    {
        return $this->status === 'approved';
    }

    /**
     * Get formatted print total
     */
    public function getFormattedPrintTotalAttribute()
    {
        return number_format($this->print_total, 2, ',', '.') . ' TL';
    }

    /**
     * Get formatted print unit price
     */
    public function getFormattedPrintUnitPriceAttribute()
    {
        return number_format($this->print_unit_price, 2, ',', '.') . ' TL';
    }

    public function displayPrintType(): string
    {
        $displayName = $this->tenantPrintSetting?->displayName();

        if (filled($displayName)) {
            return trim((string) $displayName);
        }

        return trim((string) ($this->print_type ?: '-'));
    }

    public function effectiveRequiresGraphic(): bool
    {
        if ($this->tenantPrintSetting) {
            return $this->tenantPrintSetting->effectiveRequiresGraphic();
        }

        return true;
    }

    public function effectiveRequiresProduction(): bool
    {
        if ($this->tenantPrintSetting) {
            return $this->tenantPrintSetting->effectiveRequiresProduction();
        }

        return true;
    }

    public function effectiveRequiresSetup(): bool
    {
        if ($this->tenantPrintSetting) {
            return $this->tenantPrintSetting->effectiveRequiresSetup();
        }

        return false;
    }

    public function effectiveProductionMode(): string
    {
        return (string) ($this->tenantPrintSetting?->production_mode ?: StandardPrintType::MODE_BOTH);
    }

    public function effectiveSetupTypes(): array
    {
        if ($this->tenantPrintSetting) {
            return $this->tenantPrintSetting->effectiveSetupTypes();
        }

        return [];
    }

    public function requiresSetup(): bool
    {
        if ($this->relationLoaded('setupRequirements') && $this->setupRequirements->isNotEmpty()) {
            return $this->setupRequirements->contains(
                fn (OrderItemPrintSetupRequirement $requirement) => $requirement->isRequired()
            );
        }

        return $this->effectiveRequiresSetup();
    }

    public function setupPricingEnabled(): bool
    {
        return (bool) $this->setup_pricing_enabled;
    }

    public function setupStatusSummary(): array
    {
        $requirements = $this->relationLoaded('setupRequirements')
            ? $this->setupRequirements
            : $this->setupRequirements()->with('assignedCompany')->get();

        $active = $requirements
            ->filter(fn (OrderItemPrintSetupRequirement $requirement) => $requirement->isRequired())
            ->values();

        return [
            'required' => $active->isNotEmpty(),
            'total' => $active->count(),
            'ready_count' => $active->where('status', OrderItemPrintSetupRequirement::STATUS_READY)->count(),
            'pending_count' => $active->filter(
                fn (OrderItemPrintSetupRequirement $requirement) => $requirement->isPending()
            )->count(),
            'cancelled_count' => $requirements->where('status', OrderItemPrintSetupRequirement::STATUS_CANCELLED)->count(),
            'labels' => $active->map(
                fn (OrderItemPrintSetupRequirement $requirement) => $requirement->safeSetupTypeLabel()
            )->values()->all(),
            'items' => $active->map(function (OrderItemPrintSetupRequirement $requirement): array {
                return [
                    'id' => $requirement->id,
                    'setup_type' => $requirement->setup_type,
                    'setup_type_label' => $requirement->safeSetupTypeLabel(),
                    'status' => $requirement->status,
                    'status_label' => $requirement->safeStatusLabel(),
                    'assigned_company_name' => $requirement->assignedCompany?->legal_name,
                    'cost' => $requirement->cost !== null ? (float) $requirement->cost : null,
                    'currency' => $requirement->currency ?: 'TRY',
                    'has_current_account_match' => $requirement->assigned_current_account_id !== null,
                    'note' => $requirement->note,
                    'completed_at' => optional($requirement->completed_at)?->toAtomString(),
                ];
            })->all(),
        ];
    }
}
