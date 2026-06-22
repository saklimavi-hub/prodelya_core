<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductDataHubSyncChange extends Model
{
    use HasFactory;

    protected $fillable = [
        'sync_run_id',
        'supplier_source_id',
        'supplier_product_key',
        'standard_product_id',
        'change_type',
        'old_value',
        'new_value',
        'message',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductDataHubSyncRun::class, 'sync_run_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(SupplierSource::class, 'supplier_source_id');
    }

    public function standardProduct(): BelongsTo
    {
        return $this->belongsTo(StandardProduct::class, 'standard_product_id');
    }
}
