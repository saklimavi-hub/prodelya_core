<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantPrintSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'standard_print_type_id',
        'custom_name',
        'is_active',
        'production_mode',
        'default_subcontractor_company_id',
        'default_subcontractor_current_account_id',
        'default_currency',
        'default_unit_price',
        'default_setup_cost',
        'requires_graphic',
        'requires_production',
        'requires_setup',
        'setup_types',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'default_unit_price' => 'decimal:2',
        'default_setup_cost' => 'decimal:2',
        'requires_graphic' => 'boolean',
        'requires_production' => 'boolean',
        'requires_setup' => 'boolean',
        'setup_types' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function account(): BelongsTo
    {
        return $this->tenant();
    }

    public function standardPrintType(): BelongsTo
    {
        return $this->belongsTo(StandardPrintType::class);
    }

    public function defaultSubcontractorCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'default_subcontractor_company_id');
    }

    public function defaultSubcontractorCurrentAccount(): BelongsTo
    {
        return $this->belongsTo(CurrentAccount::class, 'default_subcontractor_current_account_id');
    }

    public function displayName(): string
    {
        return trim((string) ($this->custom_name ?: $this->standardPrintType?->name ?: ('Baskı Ayarı #' . $this->id)));
    }

    public function effectiveRequiresGraphic(): bool
    {
        return (bool) $this->requires_graphic;
    }

    public function effectiveRequiresProduction(): bool
    {
        return (bool) $this->requires_production;
    }

    public function effectiveRequiresSetup(): bool
    {
        return (bool) $this->requires_setup;
    }

    public function effectiveSetupTypes(): array
    {
        return array_values(array_filter((array) $this->setup_types));
    }

    public function safeProductionModeLabel(): string
    {
        return StandardPrintType::productionModeLabels()[$this->production_mode]
            ?? ucfirst(str_replace('_', ' ', (string) $this->production_mode));
    }

    public function isInternalAllowed(): bool
    {
        return in_array($this->production_mode, [
            StandardPrintType::MODE_INTERNAL,
            StandardPrintType::MODE_BOTH,
        ], true);
    }

    public function isOutsourcedAllowed(): bool
    {
        return in_array($this->production_mode, [
            StandardPrintType::MODE_OUTSOURCED,
            StandardPrintType::MODE_BOTH,
        ], true);
    }
}
