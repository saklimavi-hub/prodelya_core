<?php

namespace App\Services\ProductDataHub;

use App\Models\StandardProduct;
use App\Models\StandardProductImage;
use App\Models\StandardProductVariant;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductImage;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantSupplierAccess;
use App\Models\TenantLocalStock;
use App\Models\SupplierSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TenantCatalogProjectionService
{
    private array $sourcePolicyCache = [];

    public function __construct(
        private readonly FallbackCategoryService $fallbackCategoryService
    ) {
    }

    public function projectForTenant(TenantAccount $tenant, array $options = []): array
    {
        $products = $this->projectionCandidates($options, $tenant);
        $standardProductIds = $products->pluck('id')->filter()->unique()->values();
        $standardVariantIds = $products
            ->flatMap(fn (StandardProduct $product) => $product->variants->pluck('id'))
            ->filter()
            ->unique()
            ->values();
        $existingCatalogProducts = $this->existingCatalogProducts($tenant, $standardProductIds);
        $existingCatalogVariants = $this->existingCatalogVariants($tenant, $standardVariantIds);
        $missingOnly = (bool) ($options['missing_only'] ?? false);

        $stats = [
            'products' => 0,
            'variants' => 0,
            'warnings' => 0,
            'created_products' => 0,
            'updated_products' => 0,
            'inactive_candidates' => 0,
            'blocked_missing_category' => 0,
            'blocked_missing_price' => 0,
            'blocked_conflict_category' => 0,
            'blocked_projection_errors' => 0,
            'projected_with_warnings' => 0,
        ];

        foreach ($products as $product) {
            $access = $this->resolveTenantSupplierAccess($tenant, $product);
            $decision = $this->resolveProjectionDecision($tenant, $product, $access);

            if (($decision['allowed'] ?? false) !== true) {
                continue;
            }

            if (($decision['should_project'] ?? false) !== true) {
                $this->syncCatalogHoldState($tenant, $product, $decision);
                $counter = $decision['counter'] ?? null;
                if ($counter && array_key_exists($counter, $stats)) {
                    $stats[$counter]++;
                }
                continue;
            }

            $existingCatalogProduct = $existingCatalogProducts->get($product->id);
            if ($missingOnly && $existingCatalogProduct) {
                $catalogProduct = $existingCatalogProduct;
            } else {
                $catalogProduct = $this->projectProduct($tenant, $product, $decision);
                $existingCatalogProducts->put($product->id, $catalogProduct);
                $stats['products']++;
                if ($catalogProduct->wasRecentlyCreated) {
                    $stats['created_products']++;
                } else {
                    $stats['updated_products']++;
                }
            }

            foreach ($product->variants as $variant) {
                if ($missingOnly && $existingCatalogVariants->has($variant->id)) {
                    continue;
                }

                $this->projectVariant($tenant, $catalogProduct, $variant);
                $existingCatalogVariants->put($variant->id, true);
                $stats['variants']++;
            }

            if (!$missingOnly || !$existingCatalogProduct) {
                $stats['warnings'] += count($catalogProduct->meta['warnings'] ?? []);
            }
            if (!empty($decision['warnings'])) {
                $stats['projected_with_warnings']++;
            }
        }

        return $stats;
    }

    public function analyzeForTenant(TenantAccount $tenant, array $options = []): array
    {
        $products = $this->projectionCandidates($options, $tenant);
        $standardProductIds = $products->pluck('id')->filter()->unique()->values();
        $standardVariantIds = $products
            ->flatMap(fn (StandardProduct $product) => $product->variants->pluck('id'))
            ->filter()
            ->unique()
            ->values();
        $missingOnly = (bool) ($options['missing_only'] ?? false);

        $existingCatalogProducts = $this->existingCatalogProducts($tenant, $standardProductIds);

        $existingCatalogVariants = $this->existingCatalogVariants($tenant, $standardVariantIds);

        $stats = [
            'candidate_products' => $products->count(),
            'candidate_variants' => $products->sum(fn (StandardProduct $product) => $product->variants->count()),
            'allowed_products' => 0,
            'projectable_products' => 0,
            'projectable_variants' => 0,
            'would_create_products' => 0,
            'would_update_products' => 0,
            'would_create_variants' => 0,
            'would_update_variants' => 0,
            'access_denied_products' => 0,
            'hold_state_updates' => 0,
            'inactive_candidates' => 0,
            'blocked_missing_category' => 0,
            'blocked_missing_price' => 0,
            'blocked_conflict_category' => 0,
            'blocked_projection_errors' => 0,
        ];

        foreach ($products as $product) {
            $access = $this->resolveTenantSupplierAccess($tenant, $product);
            $decision = $this->resolveProjectionDecision($tenant, $product, $access);

            if (($decision['allowed'] ?? false) !== true) {
                $stats['access_denied_products']++;

                continue;
            }

            $stats['allowed_products']++;

            if (($decision['should_project'] ?? false) !== true) {
                $counter = $decision['counter'] ?? null;
                if ($counter && array_key_exists($counter, $stats)) {
                    $stats[$counter]++;
                }

                if (
                    in_array(($decision['status'] ?? ''), ['missing_from_feed', 'inactive_candidate'], true)
                    && $existingCatalogProducts->has($product->id)
                ) {
                    $stats['hold_state_updates']++;
                }

                continue;
            }

            $stats['projectable_products']++;
            if ($existingCatalogProducts->has($product->id)) {
                if (!$missingOnly) {
                    $stats['would_update_products']++;
                }
            } else {
                $stats['would_create_products']++;
            }

            foreach ($product->variants as $variant) {
                if ($existingCatalogVariants->has($variant->id)) {
                    if (!$missingOnly) {
                        $stats['projectable_variants']++;
                        $stats['would_update_variants']++;
                    }
                } else {
                    $stats['projectable_variants']++;
                    $stats['would_create_variants']++;
                }
            }
        }

        return $stats;
    }

    public function projectProduct(TenantAccount $tenant, StandardProduct $product, array $decision = []): TenantCatalogProduct
    {
        $access = $this->resolveTenantSupplierAccess($tenant, $product);
        $hasVariants = $product->relationLoaded('variants') ? $product->variants->isNotEmpty() : $product->variants()->exists();
        $basePrice = (float) ($product->min_purchase_price ?? 0);
        $priceMultiplier = $access['price_multiplier'];
        $safeStock = $access['safe_stock_quantity'];
        $supplierStock = (float) ($product->total_stock_quantity ?? 0);
        $localStock = $this->resolveLocalStockQuantity($tenant, null, $product->id);
        $visibleStock = $this->calculateVisibleStock($supplierStock, $safeStock);
        $warnings = $access['warnings'];
        $standardImages = $product->relationLoaded('images') ? $product->getRelation('images') : $product->images()->get();
        $primaryStandardImage = $product->relationLoaded('primaryImage') ? $product->primaryImage : $product->primaryImage()->first();
        $legacyImages = is_array($product->images ?? null) ? $product->images : [];
        $projectionStatus = $decision['status'] ?? 'ready';
        $projectionWarnings = array_values(array_unique(array_filter(array_merge($warnings, $decision['warnings'] ?? []))));
        $fallbackCategory = null;
        $projectedCategoryId = $product->standard_category_id;

        if (blank($projectedCategoryId) && (bool) ($decision['category_missing'] ?? false)) {
            $fallbackCategory = $this->fallbackCategoryService->pendingCategory();
            $projectedCategoryId = $fallbackCategory->id;
        }

        $listPriceSnapshot = data_get($product->meta, 'price_snapshot.list_price', $product->min_purchase_price);
        $imageSnapshot = [
            'image_url' => $primaryStandardImage?->image_url ?: $product->image_url ?: ($legacyImages[0] ?? null),
            'gallery_images' => $standardImages->pluck('image_url')->all() ?: ($product->image_url ? [$product->image_url] : []),
        ];
        $existingCatalogProduct = TenantCatalogProduct::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('standard_product_id', $product->id)
            ->first();

        $catalogProduct = TenantCatalogProduct::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'standard_product_id' => $product->id,
            ],
            [
                'tenant_sku' => $this->buildProjectedTenantSku($tenant, $product),
                'name' => $product->display_name,
                'product_code' => $product->standard_product_code ?: $product->sku,
                'product_name' => $product->display_name,
                'slug' => Str::slug($product->display_name),
                'standard_category_id' => $projectedCategoryId,
                'product_family' => $product->product_family ?? 'promotion',
                'image_url' => $primaryStandardImage?->image_url ?: $product->image_url ?: ($legacyImages[0] ?? null),
                'product_url' => $product->product_url,
                'detail_url' => $product->detail_url,
                'description' => $product->description,
                'display_price' => $this->calculateDisplayPrice($basePrice, $priceMultiplier),
                'sale_price' => $this->calculateDisplayPrice($basePrice, $priceMultiplier),
                'currency' => $product->currency ?: 'TL',
                'total_stock_quantity' => $visibleStock + $localStock,
                'local_stock_quantity' => $localStock,
                'supplier_stock_quantity' => $visibleStock,
                'safe_stock_quantity' => $safeStock,
                'price_multiplier' => $priceMultiplier,
                'source_summary' => $product->source_summary,
                'visible_in_catalog' => (bool) (($decision['visible_in_catalog'] ?? true) && $access['visible_in_catalog'] && $product->visible_in_catalog),
                'visible_in_quote' => (bool) (!$hasVariants && ($decision['visible_in_quote'] ?? true) && $access['can_use_in_quotes']),
                'hidden_reason' => ($decision['visible_in_catalog'] ?? true) ? ($hasVariants ? 'Grup ürün olarak katalogda görünür, teklifte varyantları satılır.' : null) : ($decision['message'] ?? null),
                'is_featured' => (bool) ($existingCatalogProduct?->is_featured ?? false),
                'local_stock_priority' => (bool) ($existingCatalogProduct?->local_stock_priority ?? true),
                'catalog_source' => 'supplier_projection',
                'catalog_status' => $projectionStatus,
                'last_synced_at' => now(),
                'is_active' => (bool) ($decision['is_active'] ?? $product->is_active),
                'stock_quantity' => (int) round($visibleStock + $localStock),
                'allow_backorder' => false,
                'min_order_quantity' => 1,
                'tenant_attributes' => [
                    'catalog_images' => $imageSnapshot['gallery_images'],
                ],
                'meta' => [
                    'supplier_ids' => $access['supplier_ids'],
                    'can_use_in_quotes' => $access['can_use_in_quotes'],
                    'can_request_purchase' => $access['can_request_purchase'],
                    'warnings' => $projectionWarnings,
                    'category_status' => ($decision['category_status'] ?? null),
                    'category_warning' => (bool) ($decision['category_missing'] ?? false),
                    'category_missing_warning' => (bool) ($decision['category_missing'] ?? false),
                    'fallback_category_id' => $fallbackCategory?->id,
                    'fallback_category_code' => $fallbackCategory?->code,
                    'fallback_category_name' => $fallbackCategory?->full_path,
                    'standard_category_name' => $fallbackCategory?->full_path ?: $product->category,
                    'supplier_category_name' => data_get($product->source_summary, '0.supplier_category_name', data_get($product->meta, 'supplier_category_name')),
                    'supplier_category_path' => data_get($product->source_summary, '0.supplier_category_path', data_get($product->meta, 'supplier_category_path')),
                    'original_supplier_category_name' => data_get($product->source_summary, '0.supplier_category_name', data_get($product->meta, 'supplier_category_name')),
                    'original_supplier_category_path' => data_get($product->source_summary, '0.supplier_category_path', data_get($product->meta, 'supplier_category_path')),
                    'supplier_warnings' => data_get($product->meta, 'warnings', data_get($product->source_summary, '0.warnings', [])),
                    'net_price_warning' => (bool) data_get($product->meta, 'price_snapshot.net_price_warning', false),
                    'price_policy_warning' => (bool) data_get($product->meta, 'price_snapshot.price_policy_warning', false),
                    'pricing_policy_type' => data_get($product->meta, 'price_snapshot.pricing_policy_type'),
                    'supplier_warning_flag' => (bool) data_get($product->meta, 'price_snapshot.supplier_warning_flag', false),
                    'supplier_warning_type' => data_get($product->meta, 'price_snapshot.supplier_warning_type'),
                    'price_snapshot' => data_get($product->meta, 'price_snapshot'),
                    'list_price_snapshot' => $listPriceSnapshot,
                    'stock_snapshot' => [
                        'stock_quantity' => (int) round($visibleStock + $localStock),
                        'supplier_stock_quantity' => $visibleStock,
                        'local_stock_quantity' => $localStock,
                        'safe_stock_quantity' => $safeStock,
                    ],
                    'image_snapshot' => $imageSnapshot,
                    'warning_snapshot' => $projectionWarnings,
                    'gallery_images' => data_get($product->meta, 'gallery_images', []),
                    'gallery_source_fields' => data_get($product->meta, 'gallery_source_fields', []),
                    'projection_status' => $projectionStatus,
                    'projection_reason' => $decision['message'] ?? null,
                    'is_parent' => $hasVariants,
                    'is_variant' => false,
                    'is_sellable' => !$hasVariants,
                    'quote_search_visible' => !$hasVariants,
                    'parent_product_code' => $product->standard_product_code ?: $product->sku,
                    'supplier_group_code' => data_get($product->source_summary, '0.supplier_group_code'),
                    'supplier_product_code' => data_get($product->source_summary, '0.supplier_product_code'),
                ],
            ]
        );

        $this->syncCatalogProductImages($catalogProduct, $product);

        return $catalogProduct;
    }

    public function projectVariant(TenantAccount $tenant, TenantCatalogProduct $catalogProduct, StandardProductVariant $variant): TenantCatalogProductVariant
    {
        $basePrice = (float) ($variant->min_purchase_price ?? $catalogProduct->display_price ?? 0);
        $priceMultiplier = (float) ($catalogProduct->price_multiplier ?? 1);
        $safeStock = (int) ($catalogProduct->safe_stock_quantity ?? 0);
        $supplierStock = (float) ($variant->stock_quantity ?? 0);
        $localStock = $this->resolveLocalStockQuantity($tenant, $catalogProduct->id, $catalogProduct->standard_product_id);
        $visibleStock = $this->calculateVisibleStock($supplierStock, $safeStock);

        $catalogVariant = TenantCatalogProductVariant::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'tenant_catalog_product_id' => $catalogProduct->id,
                'standard_product_variant_id' => $variant->id,
            ],
            [
                'variant_code' => $variant->generated_variant_code ?: $variant->variant_code,
                'variant_name' => $variant->display_name,
                'variant_color' => $variant->variant_color,
                'variant_size' => $variant->variant_size,
                'image_url' => $variant->image_url ?: $catalogProduct->image_url,
                'display_price' => $this->calculateDisplayPrice($basePrice, $priceMultiplier),
                'currency' => $catalogProduct->currency ?: 'TL',
                'stock_quantity' => $visibleStock + $localStock,
                'local_stock_quantity' => $localStock,
                'supplier_stock_quantity' => $visibleStock,
                'safe_stock_quantity' => $safeStock,
                'visible_in_catalog' => $catalogProduct->visible_in_catalog && $variant->visible_in_catalog,
                'is_active' => $catalogProduct->is_active && $variant->is_active,
                'source_summary' => $variant->source_summary,
                'meta' => [
                    'image_fallback_used' => $variant->image_fallback_used,
                    'warnings' => data_get($variant->meta, 'warnings', data_get($variant->source_summary, 'warnings', [])),
                    'net_price_warning' => (bool) data_get($variant->meta, 'price_snapshot.net_price_warning', false),
                    'price_policy_warning' => (bool) data_get($variant->meta, 'price_snapshot.price_policy_warning', false),
                    'pricing_policy_type' => data_get($variant->meta, 'price_snapshot.pricing_policy_type'),
                    'supplier_warning_flag' => (bool) data_get($variant->meta, 'price_snapshot.supplier_warning_flag', false),
                    'supplier_warning_type' => data_get($variant->meta, 'price_snapshot.supplier_warning_type'),
                    'price_snapshot' => data_get($variant->meta, 'price_snapshot'),
                    'gallery_images' => data_get($variant->meta, 'gallery_images', []),
                    'variant_attributes' => $variant->variant_attributes,
                    'is_parent' => false,
                    'is_variant' => true,
                    'is_sellable' => true,
                    'quote_search_visible' => true,
                    'parent_product_code' => $catalogProduct->display_code,
                    'parent_product_name' => $catalogProduct->display_name,
                    'supplier_group_code' => data_get($variant->source_summary, 'supplier_group_code', data_get($catalogProduct->source_summary, '0.supplier_group_code')),
                    'supplier_product_code' => data_get($variant->source_summary, 'variant_stock_code', $variant->variant_code),
                ],
            ]
        );

        $this->syncCatalogVariantImages($catalogVariant, $variant);

        return $catalogVariant;
    }

    public function resolveTenantSupplierAccess(TenantAccount $tenant, StandardProduct $product): array
    {
        $supplierIds = collect($product->source_summary ?? [])
            ->pluck('supplier_id')
            ->filter()
            ->unique()
            ->values();

        if ($supplierIds->isEmpty() && $product->supplier_id) {
            $supplierIds = collect([$product->supplier_id]);
        }

        $accesses = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('supplier_id', $supplierIds->all())
            ->where('is_active', true)
            ->where('can_view_products', true)
            ->where('visible_in_catalog', true)
            ->get();
        $tenantHasAnyAccessRule = TenantSupplierAccess::query()
            ->where('tenant_account_id', $tenant->id)
            ->exists();

        $warnings = [];
        if ($supplierIds->isEmpty()) {
            $warnings[] = 'Ürün source_summary içinde supplier_id taşımıyor.';
        }

        $primaryAccess = $accesses->first();

        return [
            'allowed' => $primaryAccess !== null || !$tenantHasAnyAccessRule || $supplierIds->isEmpty(),
            'supplier_ids' => $supplierIds->all(),
            'price_multiplier' => (float) ($primaryAccess?->price_multiplier ?? 1),
            'safe_stock_quantity' => (int) ($primaryAccess?->safe_stock_quantity ?? 0),
            'visible_in_catalog' => (bool) ($primaryAccess?->visible_in_catalog ?? true),
            'can_use_in_quotes' => (bool) ($primaryAccess?->can_use_in_quotes ?? (!$tenantHasAnyAccessRule || $supplierIds->isEmpty())),
            'can_request_purchase' => (bool) ($primaryAccess?->can_request_purchase ?? (!$tenantHasAnyAccessRule || $supplierIds->isEmpty())),
            'warnings' => $warnings,
        ];
    }

    public function resolveProjectionDecision(TenantAccount $tenant, StandardProduct $product, array $access): array
    {
        if (($access['allowed'] ?? false) !== true) {
            return [
                'allowed' => false,
                'should_project' => false,
                'status' => 'tenant_access_missing',
            ];
        }

        $policy = $this->resolveSourceProjectionPolicy($product);
        $rawProducts = $product->relationLoaded('rawProducts') ? $product->rawProducts : $product->rawProducts()->with('source')->get();
        $syncStates = $rawProducts->pluck('normalized_payload')
            ->map(fn ($payload) => data_get($payload, '_sync_meta.last_sync_status'))
            ->filter()
            ->values();

        if ($syncStates->contains('inactive_candidate')) {
            return [
                'allowed' => true,
                'should_project' => false,
                'status' => 'inactive_candidate',
                'counter' => 'inactive_candidates',
                'message' => 'Ürün son XML beslemesinde görünmediği için pasif adayı olarak işaretlendi.',
            ];
        }

        if ($syncStates->contains('missing_from_feed')) {
            return [
                'allowed' => true,
                'should_project' => false,
                'status' => 'missing_from_feed',
                'counter' => 'inactive_candidates',
                'message' => 'Ürün son XML beslemesinde yer almıyor; silinmeden kontrol kuyruğunda tutuluyor.',
            ];
        }

        $missingCategory = blank($product->standard_category_id);
        $missingCategoryPolicy = (string) ($policy['missing_category_policy'] ?? 'warn_and_project');
        if ($missingCategory && $missingCategoryPolicy === 'block') {
            return [
                'allowed' => true,
                'should_project' => false,
                'status' => 'missing_category',
                'counter' => 'blocked_missing_category',
                'message' => 'Kategori eşlemesi tamamlanmadığı için tenant kataloğa çıkış bekletildi.',
            ];
        }

        $missingPrice = is_null($product->min_purchase_price) && is_null(data_get($product->meta, 'price_snapshot.list_price'));
        if ($missingPrice && ($policy['sync_block_on_missing_price'] ?? false)) {
            return [
                'allowed' => true,
                'should_project' => false,
                'status' => 'missing_price',
                'counter' => 'blocked_missing_price',
                'message' => 'Liste fiyatı eksik olduğu için tenant kataloğa otomatik çıkış bekletildi.',
            ];
        }

        $hasCategoryConflict = $rawProducts->contains(fn ($rawProduct) => in_array($rawProduct->mapping_status, ['needs_review', 'conflict'], true));

        $hasWarnings = (bool) $product->warning_flag
            || (bool) data_get($product->meta, 'price_snapshot.net_price_warning', false)
            || (bool) data_get($product->meta, 'price_snapshot.price_policy_warning', false)
            || (bool) data_get($product->meta, 'price_snapshot.supplier_warning_flag', false);
        $warnings = [];

        if ($missingPrice && !($policy['sync_block_on_missing_price'] ?? false)) {
            $warnings[] = 'Fiyat eksik olduğu için tenant katalogta manuel kontrol uyarısı ile gösteriliyor.';
        }

        if ($missingCategory) {
            $warnings[] = 'Kategori bekliyor';
            $warnings[] = 'Kategori eksik';
        }

        if ($hasCategoryConflict) {
            $warnings[] = 'Kategori Bekliyor';
            $warnings[] = 'Kategori önerisi review bekliyor';
        }

        if ($hasWarnings) {
            $warnings[] = 'Tedarikçi uyarısı bulunduğu için ürün tenant katalogta uyarı ile işaretlendi.';
        }

        if ($hasWarnings && !($policy['sync_allow_warning_products_to_catalog'] ?? true)) {
            return [
                'allowed' => true,
                'should_project' => false,
                'status' => 'warning_blocked',
                'counter' => 'blocked_projection_errors',
                'message' => 'Uyarılı ürünlerin tenant kataloğa otomatik çıkışı source policy ile kapalı.',
            ];
        }

        return [
            'allowed' => true,
            'should_project' => true,
            'status' => ($missingCategory || $hasCategoryConflict) ? 'category_pending' : 'ready',
            'warnings' => $warnings,
            'category_missing' => $missingCategory || $hasCategoryConflict,
            'category_conflict' => $hasCategoryConflict,
            'category_status' => $missingCategory ? 'unmapped' : ($hasCategoryConflict ? 'review' : 'mapped'),
            'visible_in_catalog' => true,
            'is_active' => true,
            'message' => $missingCategory
                ? 'Kategori eşlemesi bekliyor; ürün fallback kategori ve uyarı ile tenant kataloğa yansıtıldı.'
                : ($hasCategoryConflict
                    ? 'Kategori review bekliyor; fiyat/stok güncelliği korunarak ürün tenant kataloğa yansıtıldı.'
                    : 'Ürün tenant katalog projeksiyonuna otomatik yansıtıldı.'),
        ];
    }

    public function calculateDisplayPrice($basePrice, $priceMultiplier): float
    {
        $basePrice = (float) ($basePrice ?? 0);
        $priceMultiplier = (float) ($priceMultiplier ?: 1);

        return round($basePrice * $priceMultiplier, 4);
    }

    public function calculateVisibleStock($stock, $safeStock): float
    {
        return max(0, (float) ($stock ?? 0) - (float) ($safeStock ?? 0));
    }

    public function syncCatalogProductImages(TenantCatalogProduct $catalogProduct, StandardProduct $standardProduct): void
    {
        $images = $standardProduct->relationLoaded('images') ? $standardProduct->getRelation('images') : $standardProduct->images()->get();

        foreach ($images as $image) {
            $this->upsertCatalogImage([
                'tenant_account_id' => $catalogProduct->tenant_account_id,
                'tenant_catalog_product_id' => $catalogProduct->id,
                'tenant_catalog_product_variant_id' => null,
                'image_url' => $image->image_url,
                'image_type' => $image->image_type,
            ], [
                'standard_product_image_id' => $image->id,
                'sort_order' => $image->sort_order,
                'is_primary' => $image->is_primary,
                'fallback_used' => $image->fallback_used,
                'visible_in_catalog' => true,
                'meta' => $image->meta,
            ]);
        }
    }

    public function syncCatalogVariantImages(TenantCatalogProductVariant $catalogVariant, StandardProductVariant $standardVariant): void
    {
        foreach ($standardVariant->images as $image) {
            $this->upsertCatalogImage([
                'tenant_account_id' => $catalogVariant->tenant_account_id,
                'tenant_catalog_product_id' => $catalogVariant->tenant_catalog_product_id,
                'tenant_catalog_product_variant_id' => $catalogVariant->id,
                'image_url' => $image->image_url,
                'image_type' => $image->image_type,
            ], [
                'standard_product_image_id' => $image->id,
                'sort_order' => $image->sort_order,
                'is_primary' => $image->is_primary,
                'fallback_used' => $image->fallback_used,
                'visible_in_catalog' => true,
                'meta' => $image->meta,
            ]);
        }
    }

    private function resolveLocalStockQuantity(TenantAccount $tenant, ?int $catalogProductId, ?int $standardProductId): float
    {
        $query = TenantLocalStock::query()
            ->where('tenant_account_id', $tenant->id);

        if ($catalogProductId) {
            $query->where('tenant_catalog_product_id', $catalogProductId);
        } elseif ($standardProductId) {
            $query->whereHas('catalogProduct', function ($builder) use ($standardProductId) {
                $builder->where('standard_product_id', $standardProductId);
            });
        }

        return (float) $query->sum('quantity_available');
    }

    private function upsertCatalogImage(array $identity, array $values): TenantCatalogProductImage
    {
        return TenantCatalogProductImage::query()->updateOrCreate($identity, $values);
    }

    private function buildProjectedTenantSku(TenantAccount $tenant, StandardProduct $product): string
    {
        $baseCode = (string) ($product->standard_product_code ?: $product->sku ?: ('STD-' . $product->id));
        $tenantCode = Str::upper((string) ($tenant->panel_subdomain ?: $tenant->slug ?: ('TENANT-' . $tenant->id)));

        return $tenantCode . '-' . $baseCode;
    }

    private function resolveSourceProjectionPolicy(StandardProduct $product): array
    {
        $sourceId = collect($product->source_summary ?? [])
            ->pluck('supplier_source_id')
            ->filter()
            ->first();

        if (!$sourceId) {
            return $this->defaultProjectionPolicy();
        }

        if (!array_key_exists($sourceId, $this->sourcePolicyCache)) {
            $source = SupplierSource::query()->find($sourceId);
            $policy = (array) data_get($source?->config, 'sync_policy', []);
            $this->sourcePolicyCache[$sourceId] = array_merge($this->defaultProjectionPolicy(), $policy, [
                'sync_auto_build' => (bool) data_get($source?->config, 'sync_auto_build', true),
                'sync_auto_project_to_tenant_catalog' => (bool) data_get($source?->config, 'sync_auto_project_to_tenant_catalog', true),
            ]);
        }

        return $this->sourcePolicyCache[$sourceId];
    }

    private function defaultProjectionPolicy(): array
    {
        return [
            'sync_auto_build' => true,
            'sync_auto_project_to_tenant_catalog' => true,
            'sync_block_on_missing_category' => false,
            'missing_category_policy' => 'warn_and_project',
            'sync_block_on_missing_price' => false,
            'sync_block_on_conflict_category' => true,
            'sync_allow_warning_products_to_catalog' => true,
        ];
    }

    private function projectionCandidates(array $options = [], ?TenantAccount $tenant = null): Collection
    {
        $products = StandardProduct::query()
            ->with(['variants.images', 'images', 'category', 'rawProducts.source'])
            ->active()
            ->visibleInCatalog()
            ->when(!empty($options['supplier_ids'] ?? []), fn ($query) => $query->whereIn('supplier_id', (array) $options['supplier_ids']))
            ->when(!empty($options['standard_product_ids'] ?? []), fn ($query) => $query->whereIn('id', (array) $options['standard_product_ids']))
            ->get();

        if (!(bool) ($options['missing_only'] ?? false) || !$tenant) {
            return $products;
        }

        $existingProductIds = TenantCatalogProduct::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('standard_product_id', $products->pluck('id')->all())
            ->pluck('standard_product_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $existingVariantIds = TenantCatalogProductVariant::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('standard_product_variant_id', $products->flatMap(fn (StandardProduct $product) => $product->variants->pluck('id'))->all())
            ->pluck('standard_product_variant_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return $products
            ->filter(function (StandardProduct $product) use ($existingProductIds, $existingVariantIds) {
                if (!$existingProductIds->contains((int) $product->id)) {
                    return true;
                }

                return $product->variants->contains(
                    fn (StandardProductVariant $variant) => !$existingVariantIds->contains((int) $variant->id)
                );
            })
            ->values();
    }

    private function existingCatalogProducts(TenantAccount $tenant, Collection $standardProductIds): Collection
    {
        return TenantCatalogProduct::query()
            ->where('tenant_account_id', $tenant->id)
            ->when(
                $standardProductIds->isNotEmpty(),
                fn ($query) => $query->whereIn('standard_product_id', $standardProductIds->all()),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->get()
            ->keyBy('standard_product_id');
    }

    private function existingCatalogVariants(TenantAccount $tenant, Collection $standardVariantIds): Collection
    {
        return TenantCatalogProductVariant::query()
            ->where('tenant_account_id', $tenant->id)
            ->when(
                $standardVariantIds->isNotEmpty(),
                fn ($query) => $query->whereIn('standard_product_variant_id', $standardVariantIds->all()),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->get(['id', 'standard_product_variant_id'])
            ->keyBy('standard_product_variant_id');
    }

    private function syncCatalogHoldState(TenantAccount $tenant, StandardProduct $product, array $decision): void
    {
        if (!in_array($decision['status'] ?? '', ['missing_from_feed', 'inactive_candidate'], true)) {
            return;
        }

        TenantCatalogProduct::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('standard_product_id', $product->id)
            ->get()
            ->each(function (TenantCatalogProduct $catalogProduct) use ($decision) {
                $meta = (array) ($catalogProduct->meta ?? []);
                $catalogProduct->update([
                    'is_active' => false,
                    'visible_in_catalog' => false,
                    'visible_in_quote' => false,
                    'hidden_reason' => $decision['message'] ?? null,
                    'catalog_status' => $decision['status'],
                    'last_synced_at' => now(),
                    'meta' => array_merge($meta, [
                        'projection_status' => $decision['status'],
                        'projection_reason' => $decision['message'] ?? null,
                    ]),
                ]);
            });
    }
}
