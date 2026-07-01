<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductHubAsset extends Model
{
    use HasFactory;

    public const VISIBILITY_PRIVATE = 'private';
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_SIGNED = 'signed';

    public const STATUS_PENDING = 'pending';
    public const STATUS_STORED = 'stored';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DELETED = 'deleted';

    protected $table = 'pdh_assets';

    protected $fillable = [
        'tenant_account_id',
        'supplier_id',
        'source_id',
        'sync_run_id',
        'standard_product_id',
        'standard_product_variant_id',
        'tenant_catalog_product_id',
        'tenant_catalog_product_variant_id',
        'asset_type',
        'disk',
        'object_key',
        'original_url',
        'public_url',
        'mime_type',
        'extension',
        'size_bytes',
        'checksum_sha256',
        'width',
        'height',
        'visibility',
        'status',
        'storage_provider',
        'stored_at',
        'last_checked_at',
        'failed_reason',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'stored_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(SupplierSource::class, 'source_id');
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(ProductDataHubSyncRun::class, 'sync_run_id');
    }

    public function standardProduct(): BelongsTo
    {
        return $this->belongsTo(StandardProduct::class);
    }

    public function standardVariant(): BelongsTo
    {
        return $this->belongsTo(StandardProductVariant::class, 'standard_product_variant_id');
    }

    public function tenantCatalogProduct(): BelongsTo
    {
        return $this->belongsTo(TenantCatalogProduct::class, 'tenant_catalog_product_id');
    }

    public function tenantCatalogVariant(): BelongsTo
    {
        return $this->belongsTo(TenantCatalogProductVariant::class, 'tenant_catalog_product_variant_id');
    }
}
