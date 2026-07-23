<?php

namespace App\Services\TenantCatalog;

use App\Models\StandardCategory;
use App\Models\OrderItem;
use App\Models\TenantAccount;
use App\Models\TenantCatalogProduct;
use App\Models\TenantCatalogProductImage;
use App\Models\TenantCatalogProductVariant;
use App\Models\TenantLocalStock;
use App\Models\TenantStockReservation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TenantLocalProductWriteService
{
    public function __construct(
        private readonly CatalogFastStockActionService $catalogFastStockActionService,
        private readonly TenantCatalogProductSourceResolver $sourceResolver,
    ) {
    }

    public function create(TenantAccount $tenant, array $input, Request $request, User $user): TenantCatalogProduct
    {
        return DB::transaction(function () use ($tenant, $input, $request, $user): TenantCatalogProduct {
            $payload = $this->normalizeInput($tenant, $input);

            if ($payload['product_type'] === 'variant') {
                $product = $this->createVariantGroup($tenant, $payload, $request, $user);
            } else {
                $product = $this->createFlatProduct($tenant, $payload, $request, $user);
            }

            return $product->fresh(['variants', 'images', 'localStocks']);
        });
    }

    public function update(TenantAccount $tenant, TenantCatalogProduct $product, array $input, Request $request, User $user): TenantCatalogProduct
    {
        $this->assertOwnProduct($tenant, $product);

        return DB::transaction(function () use ($tenant, $product, $input, $request, $user): TenantCatalogProduct {
            $payload = $this->normalizeInput($tenant, $input, $product);

            if ($payload['product_type'] === 'variant') {
                $this->updateVariantGroup($tenant, $product, $payload, $request, $user);
            } else {
                $this->updateFlatProduct($tenant, $product, $payload, $request);
            }

            return $product->fresh(['variants', 'images', 'localStocks']);
        });
    }

    public function deactivate(TenantAccount $tenant, TenantCatalogProduct $product): void
    {
        $this->assertOwnProduct($tenant, $product);

        $product->update([
            'is_active' => false,
            'visible_in_catalog' => false,
            'visible_in_quote' => false,
            'hidden_reason' => 'Tenant tarafından pasifleştirildi.',
            'catalog_status' => 'local_inactive',
        ]);

        $product->variants()->update([
            'is_active' => false,
            'visible_in_catalog' => false,
        ]);
    }

    public function destroy(TenantAccount $tenant, TenantCatalogProduct $product): void
    {
        $this->assertOwnProduct($tenant, $product);

        if ($this->productHasSalesHistory($product)) {
            $product->update([
                'is_active' => false,
                'visible_in_catalog' => false,
                'visible_in_quote' => false,
                'hidden_reason' => 'Geçmiş teklif/sipariş kaydı olduğu için arşivlendi.',
                'catalog_status' => 'local_archived',
            ]);
            $product->variants()->update([
                'is_active' => false,
                'visible_in_catalog' => false,
            ]);

            return;
        }

        $product->variants()->each(function (TenantCatalogProductVariant $variant): void {
            $variant->images()->delete();
        });
        $product->images()->delete();
        $product->localStocks()->delete();
        $product->variants()->delete();
        $product->delete();
    }

    public function assertOwnProduct(TenantAccount $tenant, TenantCatalogProduct $product): void
    {
        abort_unless((int) $product->tenant_account_id === (int) $tenant->id, 403);
        abort_unless($this->sourceResolver->isOwnProduct($product), 404);
    }

    public function canDeleteVariantHard(TenantCatalogProductVariant $variant): bool
    {
        return !$this->variantHasOperationalHistory($variant);
    }

    public function normalizeInput(TenantAccount $tenant, array $input, ?TenantCatalogProduct $existingProduct = null): array
    {
        $productType = (string) ($input['product_type'] ?? ($existingProduct?->variants()->exists() ? 'variant' : 'flat'));
        $productType = in_array($productType, ['flat', 'variant'], true) ? $productType : 'flat';

        $productName = trim((string) ($input['product_name'] ?? ''));
        if ($productName === '') {
            throw ValidationException::withMessages([
                'product_name' => 'Ürün adı zorunludur.',
            ]);
        }

        $payload = [
            'product_type' => $productType,
            'product_name' => $productName,
            'description' => $this->nullableTrim($input['description'] ?? null),
            'standard_category_id' => $this->normalizeCategoryId($input['standard_category_id'] ?? null),
            'product_url' => $this->normalizeExternalPageUrl($input['product_url'] ?? null, true),
            'image_url' => $this->normalizeExternalImageUrl($input['image_url'] ?? null, true),
            'currency' => $this->normalizeCurrency($input['currency'] ?? null),
            'display_price' => $this->nullableDecimal($input['display_price'] ?? null),
            'vat_rate' => $this->decimalOrDefault($input['vat_rate'] ?? null, 20),
            'visible_in_catalog' => $this->booleanValue($input, 'visible_in_catalog', true),
            'visible_in_quote' => $this->booleanValue($input, 'visible_in_quote', true),
            'is_active' => $this->booleanValue($input, 'is_active', true),
            'is_featured' => $this->booleanValue($input, 'is_featured', false),
            'local_stock_priority' => true,
            'remove_image' => $this->booleanValue($input, 'remove_image', false),
        ];

        if ($productType === 'flat') {
            $code = $this->normalizeSku($input['product_code'] ?? null);
            if ($code === '') {
                throw ValidationException::withMessages([
                    'product_code' => 'Ürün Kodu / SKU zorunludur.',
                ]);
            }

            $this->assertSkuIsAvailable($tenant, $code, $existingProduct?->id, null);

            $payload['product_code'] = $code;
            $payload['local_stock_quantity'] = max(0, $this->decimalOrDefault($input['local_stock_quantity'] ?? null, 0));
            $payload['variant_color'] = $this->nullableTrim($input['variant_color'] ?? null);
            $payload['variant_size'] = $this->nullableTrim($input['variant_size'] ?? null);
            $payload['variant_dimensions'] = $this->nullableTrim($input['variant_dimensions'] ?? null);

            return $payload;
        }

        $groupCode = $this->normalizeSku($input['group_code'] ?? $input['product_code'] ?? null);
        if ($groupCode === '') {
            throw ValidationException::withMessages([
                'group_code' => 'Grup kodu zorunludur.',
            ]);
        }

        $this->assertParentCodeAvailable($tenant, $groupCode, $existingProduct?->id);

        $variants = collect(is_array($input['variants'] ?? null) ? $input['variants'] : [])
            ->map(fn ($row, $index) => $this->normalizeVariantRow($tenant, $row, $index, $existingProduct))
            ->filter(fn ($row) => $row['included'])
            ->values()
            ->all();

        if ($variants === []) {
            throw ValidationException::withMessages([
                'variants' => 'En az bir geçerli varyant ekleyin.',
            ]);
        }

        $codes = collect($variants)->pluck('variant_code');
        if ($codes->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'variants' => 'Aynı form içinde duplicate varyant SKU kullanılamaz.',
            ]);
        }

        $payload['group_code'] = $groupCode;
        $payload['variants'] = $variants;

        return $payload;
    }

    private function createFlatProduct(TenantAccount $tenant, array $payload, Request $request, User $user): TenantCatalogProduct
    {
        $product = TenantCatalogProduct::query()->create($this->buildFlatProductPayload($tenant, $payload));
        $this->persistProductImage($request, $tenant, $product, $payload, null);
        $this->createOpeningStockIfNeeded($tenant, $product, null, (float) ($payload['local_stock_quantity'] ?? 0), $user);

        return $product;
    }

    private function updateFlatProduct(TenantAccount $tenant, TenantCatalogProduct $product, array $payload, Request $request): void
    {
        if ($this->productHasOperationalHistory($product) && $product->display_code !== $payload['product_code']) {
            throw ValidationException::withMessages([
                'product_code' => 'Stok veya işlem geçmişi olan ürünün SKU değeri değiştirilemez.',
            ]);
        }

        $previousImageUrl = $product->image_url;
        $product->update($this->buildFlatProductPayload($tenant, $payload, $product));
        $this->persistProductImage($request, $tenant, $product, $payload, $previousImageUrl);
    }

    private function createVariantGroup(TenantAccount $tenant, array $payload, Request $request, User $user): TenantCatalogProduct
    {
        $product = TenantCatalogProduct::query()->create($this->buildParentProductPayload($tenant, $payload));
        $this->persistProductImage($request, $tenant, $product, $payload, null);

        foreach ($payload['variants'] as $index => $variantPayload) {
            $variant = $product->variants()->create($this->buildVariantPayload($product, $payload, $variantPayload));
            $this->persistVariantImage($request, $tenant, $product, $variant, $variantPayload, null, $index);
            $this->createOpeningStockIfNeeded($tenant, $product, $variant, (float) ($variantPayload['initial_stock'] ?? 0), $user);
        }
    }

    private function updateVariantGroup(TenantAccount $tenant, TenantCatalogProduct $product, array $payload, Request $request, User $user): void
    {
        if ($this->productHasOperationalHistory($product) && $product->display_code !== $payload['group_code']) {
            throw ValidationException::withMessages([
                'group_code' => 'Stok veya işlem geçmişi olan varyant grubunun grup kodu değiştirilemez.',
            ]);
        }

        $previousImageUrl = $product->image_url;
        $product->update($this->buildParentProductPayload($tenant, $payload, $product));
        $this->persistProductImage($request, $tenant, $product, $payload, $previousImageUrl);

        $existingVariants = $product->variants()->get()->keyBy('id');
        $seenVariantIds = [];

        foreach ($payload['variants'] as $index => $variantPayload) {
            $variant = null;
            if (!empty($variantPayload['id'])) {
                $variant = $existingVariants->get((int) $variantPayload['id']);
            }

            if ($variant) {
                if ($this->variantHasOperationalHistory($variant) && $variant->variant_code !== $variantPayload['variant_code']) {
                    throw ValidationException::withMessages([
                        "variants.$index.variant_code" => 'Stok veya işlem geçmişi olan varyantın SKU değeri değiştirilemez.',
                    ]);
                }

                $previousVariantImageUrl = $variant->image_url;
                $variant->update($this->buildVariantPayload($product, $payload, $variantPayload, $variant));
                $this->persistVariantImage($request, $tenant, $product, $variant, $variantPayload, $previousVariantImageUrl, $index);
                $seenVariantIds[] = $variant->id;
                continue;
            }

            $newVariant = $product->variants()->create($this->buildVariantPayload($product, $payload, $variantPayload));
            $this->persistVariantImage($request, $tenant, $product, $newVariant, $variantPayload, null, $index);
            $this->createOpeningStockIfNeeded($tenant, $product, $newVariant, (float) ($variantPayload['initial_stock'] ?? 0), $user);
            $seenVariantIds[] = $newVariant->id;
        }

        $existingVariants
            ->reject(fn (TenantCatalogProductVariant $variant) => in_array($variant->id, $seenVariantIds, true))
            ->each(function (TenantCatalogProductVariant $variant): void {
                if ($this->variantHasOperationalHistory($variant)) {
                    $variant->update([
                        'visible_in_catalog' => false,
                        'is_active' => false,
                        'meta' => array_merge((array) ($variant->meta ?? []), [
                            'quote_search_visible' => false,
                            'deactivated_reason' => 'Edit akışında kaldırıldı.',
                        ]),
                    ]);

                    return;
                }

                $variant->images()->delete();
                $variant->delete();
            });
    }

    private function buildFlatProductPayload(TenantAccount $tenant, array $payload, ?TenantCatalogProduct $existing = null): array
    {
        $existingMeta = (array) ($existing?->meta ?? []);

        return [
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => null,
            'tenant_sku' => $payload['product_code'],
            'name' => $payload['product_name'],
            'description' => $payload['description'],
            'sale_price' => $payload['display_price'],
            'stock_quantity' => (int) round((float) ($existing?->stock_quantity ?? 0)),
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => (array) ($existing?->tenant_attributes ?? []),
            'product_code' => $payload['product_code'],
            'product_name' => $payload['product_name'],
            'product_url' => $payload['product_url'],
            'detail_url' => $existing?->detail_url,
            'slug' => Str::slug($payload['product_name'] . '-' . $payload['product_code']),
            'standard_category_id' => $payload['standard_category_id'],
            'product_family' => 'local_product',
            'image_url' => $existing?->image_url,
            'display_price' => $payload['display_price'],
            'currency' => $payload['currency'],
            'total_stock_quantity' => (float) ($existing?->total_stock_quantity ?? 0),
            'local_stock_quantity' => (float) ($existing?->local_stock_quantity ?? 0),
            'supplier_stock_quantity' => 0,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => [],
            'visible_in_catalog' => $payload['visible_in_catalog'],
            'visible_in_quote' => $payload['visible_in_quote'],
            'hidden_reason' => null,
            'is_featured' => $payload['is_featured'],
            'local_stock_priority' => true,
            'catalog_source' => 'local_product',
            'catalog_status' => $payload['is_active'] ? 'local_ready' : 'local_inactive',
            'last_synced_at' => now(),
            'meta' => array_merge($existingMeta, [
                'catalog_source' => 'local_product',
                'is_parent' => false,
                'is_sellable' => true,
                'quote_search_visible' => $payload['visible_in_quote'],
                'group_code' => null,
                'variant_attributes' => array_filter([
                    'color' => $payload['variant_color'] ?? null,
                    'measure' => $payload['variant_size'] ?? null,
                    'dimensions' => $payload['variant_dimensions'] ?? null,
                ], fn ($value) => filled($value)),
                'price_snapshot' => array_merge((array) data_get($existingMeta, 'price_snapshot', []), [
                    'list_price' => $payload['display_price'],
                    'display_price' => $payload['display_price'],
                    'currency' => $payload['currency'],
                    'source_currency' => $payload['currency'],
                    'source_price' => $payload['display_price'],
                    'vat_rate' => $payload['vat_rate'],
                    'exchange_rate' => $payload['currency'] === 'TRY' ? 1.0 : null,
                    'exchange_rate_date' => now()->toDateString(),
                ]),
                'warning_snapshot' => [],
            ]),
            'is_active' => $payload['is_active'],
        ];
    }

    private function buildParentProductPayload(TenantAccount $tenant, array $payload, ?TenantCatalogProduct $existing = null): array
    {
        $existingMeta = (array) ($existing?->meta ?? []);

        return [
            'tenant_account_id' => $tenant->id,
            'standard_product_id' => null,
            'tenant_sku' => $payload['group_code'],
            'name' => $payload['product_name'],
            'description' => $payload['description'],
            'sale_price' => null,
            'stock_quantity' => (int) round((float) ($existing?->stock_quantity ?? 0)),
            'allow_backorder' => false,
            'min_order_quantity' => 1,
            'tenant_attributes' => (array) ($existing?->tenant_attributes ?? []),
            'product_code' => $payload['group_code'],
            'product_name' => $payload['product_name'],
            'product_url' => $payload['product_url'],
            'detail_url' => $existing?->detail_url,
            'slug' => Str::slug($payload['product_name'] . '-' . $payload['group_code']),
            'standard_category_id' => $payload['standard_category_id'],
            'product_family' => 'local_product',
            'image_url' => $existing?->image_url,
            'display_price' => null,
            'currency' => $payload['currency'],
            'total_stock_quantity' => (float) ($existing?->total_stock_quantity ?? 0),
            'local_stock_quantity' => (float) ($existing?->local_stock_quantity ?? 0),
            'supplier_stock_quantity' => 0,
            'safe_stock_quantity' => 0,
            'price_multiplier' => 1,
            'source_summary' => [],
            'visible_in_catalog' => $payload['visible_in_catalog'],
            'visible_in_quote' => false,
            'hidden_reason' => null,
            'is_featured' => $payload['is_featured'],
            'local_stock_priority' => true,
            'catalog_source' => 'local_product',
            'catalog_status' => $payload['is_active'] ? 'local_ready' : 'local_inactive',
            'last_synced_at' => now(),
            'meta' => array_merge($existingMeta, [
                'catalog_source' => 'local_product',
                'is_parent' => true,
                'is_sellable' => false,
                'quote_search_visible' => false,
                'group_code' => $payload['group_code'],
                'variant_attributes' => [],
                'price_snapshot' => array_merge((array) data_get($existingMeta, 'price_snapshot', []), [
                    'list_price' => null,
                    'display_price' => null,
                    'currency' => $payload['currency'],
                    'source_currency' => $payload['currency'],
                    'source_price' => null,
                    'vat_rate' => $payload['vat_rate'],
                ]),
                'warning_snapshot' => [],
            ]),
            'is_active' => $payload['is_active'],
        ];
    }

    private function buildVariantPayload(
        TenantCatalogProduct $product,
        array $parentPayload,
        array $variantPayload,
        ?TenantCatalogProductVariant $existing = null,
    ): array {
        $existingMeta = (array) ($existing?->meta ?? []);
        $fallbackImageUrl = $variantPayload['image_url'] ?: ($existing?->image_url ?: $product->image_url);

        return [
            'tenant_account_id' => $product->tenant_account_id,
            'tenant_catalog_product_id' => $product->id,
            'standard_product_variant_id' => null,
            'variant_code' => $variantPayload['variant_code'],
            'variant_name' => $variantPayload['variant_name'],
            'variant_color' => $variantPayload['variant_color'],
            'variant_size' => $variantPayload['variant_size'],
            'image_url' => $fallbackImageUrl,
            'display_price' => $variantPayload['display_price'],
            'currency' => $variantPayload['currency'],
            'stock_quantity' => (float) ($existing?->stock_quantity ?? 0),
            'local_stock_quantity' => (float) ($existing?->local_stock_quantity ?? 0),
            'supplier_stock_quantity' => 0,
            'safe_stock_quantity' => 0,
            'visible_in_catalog' => $variantPayload['visible_in_catalog'],
            'is_active' => $variantPayload['is_active'],
            'source_summary' => [],
            'meta' => array_merge($existingMeta, [
                'catalog_source' => 'local_product',
                'is_variant' => true,
                'is_sellable' => true,
                'quote_search_visible' => $variantPayload['visible_in_quote'],
                'group_code' => $parentPayload['group_code'],
                'parent_product_name' => $parentPayload['product_name'],
                'parent_product_code' => $parentPayload['group_code'],
                'variant_attributes' => array_filter([
                    'color' => $variantPayload['variant_color'],
                    'measure' => $variantPayload['variant_size'],
                    'dimensions' => $variantPayload['variant_dimensions'] ?? null,
                ], fn ($value) => filled($value)),
                'price_snapshot' => array_merge((array) data_get($existingMeta, 'price_snapshot', []), [
                    'list_price' => $variantPayload['display_price'],
                    'display_price' => $variantPayload['display_price'],
                    'currency' => $variantPayload['currency'],
                    'source_currency' => $variantPayload['currency'],
                    'source_price' => $variantPayload['display_price'],
                    'vat_rate' => $parentPayload['vat_rate'],
                    'exchange_rate' => $variantPayload['currency'] === 'TRY' ? 1.0 : null,
                    'exchange_rate_date' => now()->toDateString(),
                ]),
                'warning_snapshot' => [],
            ]),
        ];
    }

    private function createOpeningStockIfNeeded(
        TenantAccount $tenant,
        TenantCatalogProduct $product,
        ?TenantCatalogProductVariant $variant,
        float $quantity,
        User $user,
    ): void {
        if ($quantity <= 0) {
            return;
        }

        $this->catalogFastStockActionService->store($tenant, $product, $variant, [
            'entry_type' => CatalogFastStockActionService::ENTRY_TYPE_OPENING_STOCK,
            'quantity' => round($quantity, 4),
            'list_price' => 0,
            'discount_rate' => 0,
            'calculated_purchase_unit_price' => 0,
            'unit_purchase_price' => 0,
            'manual_purchase_unit_price' => false,
            'currency' => 'TRY',
            'exchange_rate' => 1,
            'exchange_rate_date' => now()->toDateString(),
            'document_no' => null,
            'entry_date' => now()->toDateString(),
            'warehouse_code' => 'LOCAL-MAIN',
            'location_code' => null,
            'notes' => 'Kendi ürün oluşturma ilk stoğu',
            'idempotency_key' => sha1(implode('|', [
                'local-product-opening-stock',
                $product->id,
                $variant?->id ?: 'flat',
                round($quantity, 4),
            ])),
        ], $user);
    }

    private function persistProductImage(Request $request, TenantAccount $tenant, TenantCatalogProduct $product, array $payload, ?string $previousImageUrl): void
    {
        if ($request->hasFile('image_upload')) {
            $uploadedUrl = $this->storeUploadedCatalogImage($tenant, $product, null, $request->file('image_upload'));
            $this->syncProductImageState($product, $uploadedUrl);
            $this->removeOwnedCatalogImageIfSafe($tenant, $product, $previousImageUrl, $uploadedUrl);

            return;
        }

        if (($payload['remove_image'] ?? false) === true) {
            $this->syncProductImageState($product, null);
            $this->removeOwnedCatalogImageIfSafe($tenant, $product, $previousImageUrl, null);

            return;
        }

        if (filled($payload['image_url'] ?? null)) {
            $this->syncProductImageState($product, $payload['image_url']);
            $this->removeOwnedCatalogImageIfSafe($tenant, $product, $previousImageUrl, $payload['image_url']);
        }
    }

    private function persistVariantImage(
        Request $request,
        TenantAccount $tenant,
        TenantCatalogProduct $product,
        TenantCatalogProductVariant $variant,
        array $payload,
        ?string $previousImageUrl,
        int $index,
    ): void {
        $uploadKey = "variant_image_uploads.$index";
        if ($request->hasFile($uploadKey)) {
            $uploadedUrl = $this->storeUploadedCatalogImage($tenant, $product, $variant, $request->file($uploadKey));
            $this->syncVariantImageState($tenant, $product, $variant, $uploadedUrl);
            $this->removeOwnedCatalogVariantImageIfSafe($tenant, $product, $variant, $previousImageUrl, $uploadedUrl);

            return;
        }

        if (($payload['remove_image'] ?? false) === true) {
            $fallback = $product->image_url;
            $this->syncVariantImageState($tenant, $product, $variant, $fallback);
            $this->removeOwnedCatalogVariantImageIfSafe($tenant, $product, $variant, $previousImageUrl, $fallback);

            return;
        }

        $resolved = $payload['image_url'] ?: $product->image_url;
        $this->syncVariantImageState($tenant, $product, $variant, $resolved);
        $this->removeOwnedCatalogVariantImageIfSafe($tenant, $product, $variant, $previousImageUrl, $resolved);
    }

    private function storeUploadedCatalogImage(
        TenantAccount $tenant,
        TenantCatalogProduct $product,
        ?TenantCatalogProductVariant $variant,
        UploadedFile $file,
    ): string {
        $this->assertUploadIsSupported($file);

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $filename = (string) Str::uuid() . '.' . $extension;
        $basePath = $variant
            ? sprintf('tenants/%d/catalog/products/%d/variants/%d', $tenant->id, $product->id, $variant->id ?: 0)
            : sprintf('tenants/%d/catalog/products/%d', $tenant->id, $product->id);
        $path = $file->storeAs($basePath, $filename, 'public');

        return Storage::disk('public')->url($path);
    }

    private function syncProductImageState(TenantCatalogProduct $product, ?string $imageUrl): void
    {
        $tenantAttributes = (array) ($product->tenant_attributes ?? []);
        $tenantAttributes['catalog_images'] = filled($imageUrl) ? [$imageUrl] : [];

        $product->forceFill([
            'image_url' => $imageUrl,
            'tenant_attributes' => $tenantAttributes,
        ])->save();

        if (!filled($imageUrl)) {
            $product->images()
                ->whereNull('tenant_catalog_product_variant_id')
                ->delete();

            return;
        }

        $primaryImage = $product->images()->updateOrCreate(
            [
                'tenant_account_id' => $product->tenant_account_id,
                'tenant_catalog_product_id' => $product->id,
                'tenant_catalog_product_variant_id' => null,
                'is_primary' => true,
            ],
            [
                'image_url' => $imageUrl,
                'image_type' => 'tenant_local_product',
                'sort_order' => 0,
                'fallback_used' => false,
                'visible_in_catalog' => true,
                'meta' => ['managed_by' => 'local_product_form'],
            ]
        );

        $product->images()
            ->whereNull('tenant_catalog_product_variant_id')
            ->where('id', '!=', $primaryImage->id)
            ->update(['is_primary' => false]);
    }

    private function syncVariantImageState(
        TenantAccount $tenant,
        TenantCatalogProduct $product,
        TenantCatalogProductVariant $variant,
        ?string $imageUrl,
    ): void {
        $variant->forceFill([
            'image_url' => $imageUrl ?: $product->image_url,
        ])->save();

        if (!filled($imageUrl)) {
            $variant->images()->delete();

            return;
        }

        $primaryImage = TenantCatalogProductImage::query()->updateOrCreate(
            [
                'tenant_account_id' => $tenant->id,
                'tenant_catalog_product_id' => $product->id,
                'tenant_catalog_product_variant_id' => $variant->id,
                'is_primary' => true,
            ],
            [
                'image_url' => $imageUrl,
                'image_type' => 'tenant_local_product_variant',
                'sort_order' => 0,
                'fallback_used' => $imageUrl === $product->image_url,
                'visible_in_catalog' => true,
                'meta' => ['managed_by' => 'local_product_variant_form'],
            ]
        );

        $variant->images()
            ->where('id', '!=', $primaryImage->id)
            ->update(['is_primary' => false]);
    }

    private function removeOwnedCatalogImageIfSafe(TenantAccount $tenant, TenantCatalogProduct $product, ?string $oldUrl, ?string $newUrl): void
    {
        if (!filled($oldUrl) || $oldUrl === $newUrl) {
            return;
        }

        $path = $this->ownedPublicDiskPathForCatalogImage($tenant, $product, null, $oldUrl);
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function removeOwnedCatalogVariantImageIfSafe(
        TenantAccount $tenant,
        TenantCatalogProduct $product,
        TenantCatalogProductVariant $variant,
        ?string $oldUrl,
        ?string $newUrl,
    ): void {
        if (!filled($oldUrl) || $oldUrl === $newUrl) {
            return;
        }

        $path = $this->ownedPublicDiskPathForCatalogImage($tenant, $product, $variant, $oldUrl);
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function ownedPublicDiskPathForCatalogImage(
        TenantAccount $tenant,
        TenantCatalogProduct $product,
        ?TenantCatalogProductVariant $variant,
        ?string $url,
    ): ?string {
        if (!filled($url)) {
            return null;
        }

        $path = ltrim((string) parse_url((string) $url, PHP_URL_PATH), '/');
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        $prefix = $variant
            ? sprintf('tenants/%d/catalog/products/%d/variants/%d/', $tenant->id, $product->id, $variant->id)
            : sprintf('tenants/%d/catalog/products/%d/', $tenant->id, $product->id);

        return str_starts_with($path, $prefix) ? $path : null;
    }

    private function normalizeVariantRow(TenantAccount $tenant, mixed $row, int $index, ?TenantCatalogProduct $existingProduct): array
    {
        $row = is_array($row) ? $row : [];
        $included = !array_key_exists('included', $row) || filter_var($row['included'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== false;
        $existingId = filled($row['id'] ?? null) ? (int) $row['id'] : null;
        $variantCode = $this->normalizeSku($row['variant_code'] ?? null);
        $variantName = trim((string) ($row['variant_name'] ?? ''));
        $visibleInQuote = $this->booleanValue($row, 'visible_in_quote', true);

        if ($included && $variantCode === '') {
            throw ValidationException::withMessages([
                "variants.$index.variant_code" => 'Varyant SKU zorunludur.',
            ]);
        }

        if ($included && $variantName === '') {
            throw ValidationException::withMessages([
                "variants.$index.variant_name" => 'Varyant adı zorunludur.',
            ]);
        }

        if ($included) {
            $this->assertSkuIsAvailable($tenant, $variantCode, $existingProduct?->id, $existingId);
        }

        return [
            'id' => $existingId,
            'included' => $included,
            'variant_code' => $variantCode,
            'variant_name' => $variantName !== '' ? $variantName : $this->buildVariantName($existingProduct?->product_name ?? '', $row['variant_color'] ?? null, $row['variant_size'] ?? null, $row['variant_dimensions'] ?? null),
            'variant_color' => $this->nullableTrim($row['variant_color'] ?? null),
            'variant_size' => $this->nullableTrim($row['variant_size'] ?? null),
            'variant_dimensions' => $this->nullableTrim($row['variant_dimensions'] ?? null),
            'display_price' => $this->nullableDecimal($row['display_price'] ?? null),
            'currency' => $this->normalizeCurrency($row['currency'] ?? null),
            'initial_stock' => max(0, $this->decimalOrDefault($row['initial_stock'] ?? null, 0)),
            'image_url' => $this->normalizeExternalImageUrl($row['image_url'] ?? null, true),
            'visible_in_catalog' => $this->booleanValue($row, 'visible_in_catalog', true),
            'visible_in_quote' => $visibleInQuote,
            'is_active' => $this->booleanValue($row, 'is_active', true),
            'remove_image' => $this->booleanValue($row, 'remove_image', false),
        ];
    }

    private function normalizeCategoryId(mixed $value): ?int
    {
        if (!filled($value)) {
            return null;
        }

        $categoryId = (int) $value;
        $exists = StandardCategory::query()
            ->permanentBackbone()
            ->whereKey($categoryId)
            ->exists();

        if (!$exists) {
            abort(422, 'Geçerli ve aktif bir katalog kategorisi seçin.');
        }

        return $categoryId;
    }
    private function assertSkuIsAvailable(TenantAccount $tenant, string $sku, ?int $ignoreProductId = null, ?int $ignoreVariantId = null): void
    {
        $productExists = TenantCatalogProduct::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('product_code', $sku)
            ->when($ignoreProductId, fn ($query) => $query->where('id', '!=', $ignoreProductId))
            ->exists();

        if ($productExists) {
            throw ValidationException::withMessages([
                'product_code' => 'Bu SKU aynı Abone Firma içinde zaten kullanılıyor.',
            ]);
        }

        $variantExists = TenantCatalogProductVariant::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('variant_code', $sku)
            ->when($ignoreVariantId, fn ($query) => $query->where('id', '!=', $ignoreVariantId))
            ->exists();

        if ($variantExists) {
            throw ValidationException::withMessages([
                'product_code' => 'Bu SKU aynı Abone Firma içinde zaten kullanılıyor.',
            ]);
        }
    }

    private function assertParentCodeAvailable(TenantAccount $tenant, string $groupCode, ?int $ignoreProductId = null): void
    {
        $exists = TenantCatalogProduct::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('product_code', $groupCode)
            ->when($ignoreProductId, fn ($query) => $query->where('id', '!=', $ignoreProductId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'group_code' => 'Bu grup kodu aynı Abone Firma içinde zaten kullanılıyor.',
            ]);
        }
    }

    private function assertUploadIsSupported(UploadedFile $file): void
    {
        $mime = strtolower((string) ($file->getMimeType() ?? ''));
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension() ?: ''));
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

        if (!in_array($mime, $allowedMimes, true) || !in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                'image_upload' => 'SVG dosyaları güvenlik nedeniyle doğrudan yüklenemez. Lütfen PNG, JPG veya WEBP kullanın.',
            ]);
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'image_upload' => 'Görsel boyutu en fazla 5 MB olabilir.',
            ]);
        }
    }

    private function productHasOperationalHistory(TenantCatalogProduct $product): bool
    {
        return $this->productHasSalesHistory($product)
            || TenantLocalStock::query()->where('tenant_catalog_product_id', $product->id)->where(function ($query) {
                $query->where('quantity_on_hand', '>', 0)
                    ->orWhere('quantity_reserved', '>', 0)
                    ->orWhere('quantity_available', '>', 0);
            })->exists()
            || $product->stockMovements()->exists()
            || $product->variants()->whereHas('catalogProduct')->exists();
    }

    private function productHasSalesHistory(TenantCatalogProduct $product): bool
    {
        return OrderItem::query()
            ->where('tenant_catalog_product_id', $product->id)
            ->exists();
    }

    private function variantHasOperationalHistory(TenantCatalogProductVariant $variant): bool
    {
        return OrderItem::query()
                ->where('tenant_catalog_product_variant_id', $variant->id)
                ->exists()
            || TenantLocalStock::query()
                ->where('tenant_catalog_product_variant_id', $variant->id)
                ->where(function ($query) {
                    $query->where('quantity_on_hand', '>', 0)
                        ->orWhere('quantity_reserved', '>', 0)
                        ->orWhere('quantity_available', '>', 0);
                })->exists()
            || TenantStockReservation::query()
                ->whereHas('stock', fn ($query) => $query->where('tenant_catalog_product_variant_id', $variant->id))
                ->whereIn('status', ['active', 'reserved'])
                ->exists();
    }

    private function normalizeSku(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        $ascii = Str::of(Str::ascii($raw))
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '-')
            ->replaceMatches('/-+/', '-')
            ->trim('-')
            ->toString();

        return $ascii;
    }

    private function normalizeCurrency(mixed $value): string
    {
        return match (strtoupper(trim((string) ($value ?? 'TRY')))) {
            'TL', 'TRY' => 'TRY',
            'USD' => 'USD',
            'EUR' => 'EUR',
            default => throw ValidationException::withMessages([
                'currency' => 'Geçerli para birimi seçin.',
            ]),
        };
    }

    private function normalizeExternalPageUrl(mixed $value, bool $strict): ?string
    {
        if (!filled($value)) {
            return null;
        }

        $url = trim((string) $value);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            if ($strict) {
                throw ValidationException::withMessages([
                    'product_url' => 'Ürün URL yalnız http veya https olabilir.',
                ]);
            }

            return null;
        }

        return $url;
    }

    private function normalizeExternalImageUrl(mixed $value, bool $strict): ?string
    {
        if (!filled($value)) {
            return null;
        }

        $url = trim((string) $value);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            if ($strict) {
                throw ValidationException::withMessages([
                    'image_url' => 'Görsel URL yalnız http veya https olabilir.',
                ]);
            }

            return null;
        }

        return $url;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableDecimal(mixed $value): ?float
    {
        if (!filled($value)) {
            return null;
        }

        if (!is_numeric(str_replace(',', '.', (string) $value))) {
            throw ValidationException::withMessages([
                'display_price' => 'Sayısal bir değer girin.',
            ]);
        }

        return round((float) str_replace(',', '.', (string) $value), 4);
    }

    private function decimalOrDefault(mixed $value, float $default): float
    {
        if (!filled($value)) {
            return $default;
        }

        if (!is_numeric(str_replace(',', '.', (string) $value))) {
            throw ValidationException::withMessages([
                'value' => 'Sayısal bir değer girin.',
            ]);
        }

        return round((float) str_replace(',', '.', (string) $value), 4);
    }

    private function buildVariantName(string $productName, mixed $color, mixed $measure, mixed $dimensions): string
    {
        $parts = array_values(array_filter([
            $this->nullableTrim($color),
            $this->nullableTrim($measure),
            $this->nullableTrim($dimensions),
        ]));

        if ($productName === '') {
            return implode(' / ', $parts);
        }

        if ($parts === []) {
            return $productName;
        }

        return trim($productName . ' ' . implode(' / ', $parts));
    }

    private function booleanValue(array $source, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $source)) {
            return $default;
        }

        return filter_var($source[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
