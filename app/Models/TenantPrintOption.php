<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantPrintOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'tenant_print_setting_id',
        'standard_print_type_id',
        'name',
        'code',
        'description',
        'is_active',
        'sort_order',
        'is_default',
        'default_unit_price',
        'requires_setup',
        'setup_type',
        'setup_status_default',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'is_default' => 'boolean',
        'default_unit_price' => 'decimal:4',
        'requires_setup' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function tenantPrintSetting(): BelongsTo
    {
        return $this->belongsTo(TenantPrintSetting::class, 'tenant_print_setting_id');
    }

    public function standardPrintType(): BelongsTo
    {
        return $this->belongsTo(StandardPrintType::class, 'standard_print_type_id');
    }

    public function displayName(): string
    {
        return trim((string) ($this->name ?: ('Baskı Seçeneği #' . $this->id)));
    }
}
