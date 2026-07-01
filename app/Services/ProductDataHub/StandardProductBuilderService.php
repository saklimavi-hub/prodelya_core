<?php

namespace App\Services\ProductDataHub;

use App\Models\StandardCategory;
use App\Models\StandardProduct;
use App\Models\StandardProductImage;
use App\Models\StandardProductVariant;
use App\Models\SupplierCategoryMapping;
use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Models\SupplierSource;
use App\Services\ProductCodeNormalizerService;
use Illuminate\Support\Str;

class StandardProductBuilderService
{
    public function __construct(
        private readonly ProductCodeNormalizerService $normalizer
    ) {
    }

    public function buildFromRawProduct(SupplierProductRaw $rawProduct): array
    {
        $standardProduct = $this->createOrUpdateStandardProduct($rawProduct);
        $productCreated = $standardProduct->wasRecentlyCreated;
        $this->syncStandardProductImages($standardProduct, $rawProduct);
        $variantCount = 0;
        $createdVariants = 0;
        $updatedVariants = 0;

        foreach ($this->resolveRawVariantsForBuild($rawProduct) as $rawVariant) {
            $variant = $this->createOrUpdateStandardVariant($standardProduct, $rawProduct, $rawVariant);
            $this->syncStandardVariantImages($variant, $rawVariant);
            $variantCount++;

            if ($variant->wasRecentlyCreated) {
                $createdVariants++;
            } else {
                $updatedVariants++;
            }
        }

        $rawProduct->forceFill([
            'standard_product_id' => $standardProduct->id,
            'sync_status' => 'processed',
            'mapping_status' => $standardProduct->standard_category_id ? 'mapped' : ($rawProduct->mapping_status ?: 'pending'),
        ])->save();

        $this->updateAggregates($standardProduct);

        return [
            'standard_product_id' => $standardProduct->id,
            'standard_product_code' => $standardProduct->standard_product_code,
            'variant_count' => $variantCount,
            'created_products' => $productCreated ? 1 : 0,
            'updated_products' => $productCreated ? 0 : 1,
            'created_variants' => $createdVariants,
            'updated_variants' => $updatedVariants,
            'warnings' => array_values(array_unique(array_merge(
                $rawProduct->warnings ?? [],
                $rawProduct->variants->flatMap(fn (SupplierProductVariantRaw $variant) => $variant->warnings ?? [])->all()
            ))),
            'errors' => array_values(array_unique(array_merge(
                $rawProduct->errors ?? [],
                $rawProduct->variants->flatMap(fn (SupplierProductVariantRaw $variant) => $variant->errors ?? [])->all()
            ))),
        ];
    }

    protected function resolveRawVariantsForBuild(SupplierProductRaw $rawProduct)
    {
        $directVariants = $rawProduct->relationLoaded('variants')
            ? $rawProduct->variants
            : $rawProduct->variants()->get();

        $groupCode = trim((string) ($rawProduct->supplier_group_code ?: ''));
        if ($groupCode === '') {
            return $directVariants;
        }

        $groupVariants = SupplierProductVariantRaw::query()
            ->where('supplier_source_id', $rawProduct->supplier_source_id)
            ->where('supplier_group_code', $groupCode)
            ->get();

        return $directVariants
            ->merge($groupVariants)
            ->unique(fn (SupplierProductVariantRaw $variant) => $variant->generated_variant_code ?: $variant->variant_stock_code ?: $variant->variant_code ?: $variant->id)
            ->values();
    }

    public function buildManyFromSource(SupplierSource $source): array
    {
        $results = [
            'processed' => 0,
            'variants' => 0,
            'created_products' => 0,
            'updated_products' => 0,
            'created_variants' => 0,
            'updated_variants' => 0,
            'warnings' => 0,
            'errors' => 0,
            'skipped' => 0,
        ];

        $rawProducts = SupplierProductRaw::query()
            ->with('variants')
            ->where('supplier_source_id', $source->id)
            ->whereIn('sync_status', ['staged', 'processed'])
            ->get();

        foreach ($rawProducts as $rawProduct) {
            $result = $this->buildFromRawProduct($rawProduct);
            $results['processed']++;
            $results['variants'] += $result['variant_count'];
            $results['created_products'] += $result['created_products'];
            $results['updated_products'] += $result['updated_products'];
            $results['created_variants'] += $result['created_variants'];
            $results['updated_variants'] += $result['updated_variants'];
            $results['warnings'] += count($result['warnings']);
            $results['errors'] += count($result['errors']);
        }

        return $results;
    }

    public function resolveStandardProductCode(SupplierProductRaw $rawProduct): string
    {
        $generatedCode = (string) data_get($rawProduct->normalized_payload, 'generated_product_code', '');
        if ($generatedCode !== '') {
            return $this->normalizer->normalizeCode($generatedCode);
        }

        $prefix = $this->resolvePrefix($rawProduct);
        if (filled($rawProduct->supplier_group_code)) {
            return $this->normalizer->applySupplierPrefix($prefix, (string) $rawProduct->supplier_group_code);
        }

        if (filled($rawProduct->supplier_product_code)) {
            return $this->normalizer->applySupplierPrefix($prefix, (string) $rawProduct->supplier_product_code);
        }

        return $this->normalizer->applySupplierPrefix($prefix, substr((string) $rawProduct->import_hash, 0, 12));
    }

    public function resolveProductName(SupplierProductRaw $rawProduct): string
    {
        return (string) (
            $rawProduct->product_name
            ?: data_get($rawProduct->normalized_payload, 'base_product_name')
            ?: data_get($rawProduct->normalized_payload, 'product_name')
            ?: $rawProduct->supplier_product_code
            ?: $rawProduct->source_name
            ?: 'Standart Ürün'
        );
    }

    public function resolveCategory(SupplierProductRaw $rawProduct): ?int
    {
        $overrideCategoryId = data_get($rawProduct->normalized_payload, 'category_override_standard_category_id');
        if ($overrideCategoryId) {
            return (int) $overrideCategoryId;
        }

        if ($rawProduct->standard_category_id) {
            return $rawProduct->standard_category_id;
        }

        if (blank($rawProduct->supplier_category_name)) {
            return null;
        }

        return SupplierCategoryMapping::query()
            ->where('supplier_id', $rawProduct->supplier_id)
            ->where(function ($query) use ($rawProduct) {
                $query->whereNull('supplier_source_id')
                    ->orWhere('supplier_source_id', $rawProduct->supplier_source_id);
            })
            ->where('source_category', $rawProduct->supplier_category_name)
            ->value('standard_category_id');
    }

    public function createOrUpdateStandardProduct(SupplierProductRaw $rawProduct): StandardProduct
    {
        $standardProductCode = $this->resolveStandardProductCode($rawProduct);
        $categoryId = $this->resolveCategory($rawProduct);
        $category = $categoryId ? StandardCategory::query()->find($categoryId) : null;
        $supplierCategoryName = $rawProduct->supplier_category_name ?: $rawProduct->source_category;
        $supplierCategoryPath = data_get($rawProduct->normalized_payload, 'supplier_category_path')
            ?: data_get($rawProduct->normalized_payload, 'category_path')
            ?: $supplierCategoryName;
        $productName = $this->resolveProductName($rawProduct);
        $imageUrl = $rawProduct->image_url
            ?: data_get($rawProduct->normalized_payload, 'parent_image_url')
            ?: $rawProduct->variants()->whereNotNull('variant_image_url')->value('variant_image_url');

        $product = StandardProduct::query()->firstOrNew([
            'standard_product_code' => $standardProductCode,
        ]);

        $baseListPrice = data_get($rawProduct->normalized_payload, 'list_price');

        $product->forceFill([
            'tenant_account_id' => $rawProduct->tenant_account_id,
            'supplier_id' => $rawProduct->supplier_id,
            'supplier_product_raw_id' => $rawProduct->id,
            'standard_product_code' => $standardProductCode,
            'sku' => $standardProductCode,
            'product_name' => $productName,
            'base_product_name' => data_get($rawProduct->normalized_payload, 'base_product_name'),
            'name' => $productName,
            'slug' => Str::slug($productName),
            'standard_category_id' => $categoryId,
            'category' => $category?->full_path,
            'product_family' => $category?->product_family ?? 'promotion',
            'description' => $rawProduct->description ?: $rawProduct->source_description,
            'image_url' => $imageUrl,
            'images' => $imageUrl ? [$imageUrl] : null,
            'product_url' => $rawProduct->product_url ?: $rawProduct->detail_url,
            'detail_url' => $rawProduct->detail_url ?: $rawProduct->product_url,
            'vat_rate' => $rawProduct->vat_rate,
            'currency' => $rawProduct->currency ?: $rawProduct->source_currency ?: 'TL',
            'min_purchase_price' => $baseListPrice,
            'max_purchase_price' => $baseListPrice,
            'total_stock_quantity' => $rawProduct->stock_quantity,
            'supplier_count' => 1,
            'variant_count' => 0,
            'warning_flag' => (bool) $rawProduct->warning_flag,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [
                [
                    'supplier_id' => $rawProduct->supplier_id,
                    'supplier_source_id' => $rawProduct->supplier_source_id,
                    'raw_product_id' => $rawProduct->id,
                    'supplier_product_code' => $rawProduct->supplier_product_code,
                    'supplier_group_code' => $rawProduct->supplier_group_code,
                    'supplier_category_name' => $supplierCategoryName,
                    'supplier_category_path' => $supplierCategoryPath,
                    'import_hash' => $rawProduct->import_hash,
                    'stock_quantity' => $rawProduct->stock_quantity ?? data_get($rawProduct->normalized_payload, 'stock_quantity'),
                    'total_variant_stock_quantity' => data_get($rawProduct->normalized_payload, 'total_variant_stock_quantity'),
                    'list_price' => data_get($rawProduct->normalized_payload, 'list_price'),
                    'closed_list_price' => data_get($rawProduct->normalized_payload, 'closed_list_price'),
                    'net_price' => data_get($rawProduct->normalized_payload, 'net_price'),
                    'discount_rate' => data_get($rawProduct->normalized_payload, 'discount_rate'),
                    'alternative_price' => data_get($rawProduct->normalized_payload, 'alternative_price'),
                    'usd_price' => data_get($rawProduct->normalized_payload, 'usd_price'),
                    'net_price_warning' => (bool) data_get($rawProduct->normalized_payload, 'net_price_warning', false),
                    'price_policy_warning' => (bool) data_get($rawProduct->normalized_payload, 'price_policy_warning', false),
                    'pricing_policy_type' => data_get($rawProduct->normalized_payload, 'pricing_policy_type'),
                    'supplier_warning_flag' => (bool) data_get($rawProduct->normalized_payload, 'supplier_warning_flag', false),
                    'supplier_warning_type' => data_get($rawProduct->normalized_payload, 'supplier_warning_type'),
                    'gallery_images' => data_get($rawProduct->normalized_payload, 'gallery_images', []),
                ],
            ],
                'meta' => [
                    'source_type' => 'raw_product',
                    'normalized_payload' => $rawProduct->normalized_payload,
                    'category_status' => $categoryId ? 'mapped' : 'unmapped',
                    'category_warning' => !$categoryId,
                    'category_missing_warning' => !$categoryId,
                    'supplier_category_name' => $supplierCategoryName,
                    'supplier_category_path' => $supplierCategoryPath,
                    'original_supplier_category_name' => $supplierCategoryName,
                    'original_supplier_category_path' => $supplierCategoryPath,
                    'category_override' => [
                        'standard_category_id' => data_get($rawProduct->normalized_payload, 'category_override_standard_category_id'),
                        'category_name' => data_get($rawProduct->normalized_payload, 'category_override_name'),
                        'note' => data_get($rawProduct->normalized_payload, 'category_override_note'),
                        'apply_to_rule' => (bool) data_get($rawProduct->normalized_payload, 'category_override_apply_to_rule', false),
                        'applied_at' => data_get($rawProduct->normalized_payload, 'category_override_applied_at'),
                    ],
                    'warnings' => $rawProduct->warnings ?? [],
                    'stock_snapshot' => [
                        'stock_quantity' => $rawProduct->stock_quantity ?? data_get($rawProduct->normalized_payload, 'stock_quantity'),
                        'total_variant_stock_quantity' => data_get($rawProduct->normalized_payload, 'total_variant_stock_quantity'),
                        'source_stock' => $rawProduct->source_stock,
                    ],
                    'price_snapshot' => [
                        'purchase_price' => $rawProduct->purchase_price,
                        'list_price' => data_get($rawProduct->normalized_payload, 'list_price'),
                    'closed_list_price' => data_get($rawProduct->normalized_payload, 'closed_list_price'),
                    'net_price' => data_get($rawProduct->normalized_payload, 'net_price'),
                    'discount_rate' => data_get($rawProduct->normalized_payload, 'discount_rate'),
                    'alternative_price' => data_get($rawProduct->normalized_payload, 'alternative_price'),
                    'usd_price' => data_get($rawProduct->normalized_payload, 'usd_price'),
                    'net_price_warning' => (bool) data_get($rawProduct->normalized_payload, 'net_price_warning', false),
                    'price_policy_warning' => (bool) data_get($rawProduct->normalized_payload, 'price_policy_warning', false),
                    'pricing_policy_type' => data_get($rawProduct->normalized_payload, 'pricing_policy_type'),
                    'supplier_warning_flag' => (bool) data_get($rawProduct->normalized_payload, 'supplier_warning_flag', false),
                    'supplier_warning_type' => data_get($rawProduct->normalized_payload, 'supplier_warning_type'),
                ],
                'gallery_images' => data_get($rawProduct->normalized_payload, 'gallery_images', []),
                'gallery_source_fields' => data_get($rawProduct->normalized_payload, 'gallery_source_fields', []),
                'warnings' => $rawProduct->warnings ?? [],
            ],
        ]);
        $product->save();

        return $product;
    }

    public function createOrUpdateStandardVariant(StandardProduct $product, SupplierProductRaw $rawProduct, SupplierProductVariantRaw $rawVariant): StandardProductVariant
    {
        $generatedVariantCode = $rawVariant->generated_variant_code
            ?: $this->normalizer->normalizeCode(
                ($product->standard_product_code ?: 'STD')
                . '-'
                . ($rawVariant->variant_color ?: $rawVariant->variant_stock_code ?: $rawVariant->variant_code ?: $rawVariant->variant_id ?: 'VAR')
            );

        $query = StandardProductVariant::query()->where('standard_product_id', $product->id);
        $variant = filled($generatedVariantCode)
            ? $query->where('generated_variant_code', $generatedVariantCode)->first()
            : $query->where('variant_code', $rawVariant->variant_code ?: $rawVariant->variant_stock_code)->first();

        $variant = $variant ?: new StandardProductVariant();

        $variantDisplayPrice = data_get($rawVariant->normalized_payload, 'list_price', data_get($rawProduct->normalized_payload, 'list_price'));
        $variantPurchasePrice = data_get($rawVariant->normalized_payload, 'purchase_price', $rawProduct->purchase_price);
        $displayVariantColor = data_get($rawVariant->normalized_payload, 'display_variant_color', $rawVariant->variant_color);
        $displayVariantSize = data_get($rawVariant->normalized_payload, 'display_size', data_get($rawVariant->normalized_payload, 'variant_size', $rawVariant->variant_size));
        $displayVariantAttributes = data_get($rawVariant->normalized_payload, 'display_variant_attributes', $rawVariant->variant_attributes);

        $variant->forceFill([
            'standard_product_id' => $product->id,
            'tenant_account_id' => $rawVariant->tenant_account_id ?? $rawProduct->tenant_account_id,
            'variant_code' => $rawVariant->variant_code ?: $rawVariant->variant_stock_code,
            'generated_variant_code' => $generatedVariantCode,
            'variant_name' => $rawVariant->variant_name ?: $displayVariantColor ?: $generatedVariantCode,
            'variant_color' => $displayVariantColor,
            'variant_size' => $displayVariantSize,
            'variant_attributes' => $displayVariantAttributes,
            'image_url' => $rawVariant->variant_image_url ?: $product->image_url,
            'image_fallback_used' => (bool) $rawVariant->image_fallback_used,
            'stock_quantity' => $rawVariant->variant_stock_quantity,
            'min_purchase_price' => $variantDisplayPrice,
            'max_purchase_price' => $variantDisplayPrice,
            'supplier_count' => 1,
            'is_active' => true,
            'visible_in_catalog' => true,
            'source_summary' => [
                'raw_variant_id' => $rawVariant->id,
                'variant_id' => $rawVariant->variant_id,
                'variant_stock_code' => $rawVariant->variant_stock_code,
                'supplier_id' => $rawVariant->supplier_id,
                'supplier_source_id' => $rawVariant->supplier_source_id,
                'stock_quantity' => $rawVariant->variant_stock_quantity,
                'total_variant_stock_quantity' => data_get($rawVariant->normalized_payload, 'total_variant_stock_quantity', data_get($rawProduct->normalized_payload, 'total_variant_stock_quantity')),
                'list_price' => data_get($rawVariant->normalized_payload, 'list_price', data_get($rawProduct->normalized_payload, 'list_price')),
                'closed_list_price' => data_get($rawVariant->normalized_payload, 'closed_list_price', data_get($rawProduct->normalized_payload, 'closed_list_price')),
                'net_price' => data_get($rawVariant->normalized_payload, 'net_price', data_get($rawProduct->normalized_payload, 'net_price')),
                'discount_rate' => data_get($rawVariant->normalized_payload, 'discount_rate', data_get($rawProduct->normalized_payload, 'discount_rate')),
                'alternative_price' => data_get($rawVariant->normalized_payload, 'alternative_price', data_get($rawProduct->normalized_payload, 'alternative_price')),
                'usd_price' => data_get($rawVariant->normalized_payload, 'usd_price', data_get($rawProduct->normalized_payload, 'usd_price')),
                'net_price_warning' => (bool) data_get($rawVariant->normalized_payload, 'net_price_warning', data_get($rawProduct->normalized_payload, 'net_price_warning', false)),
                'price_policy_warning' => (bool) data_get($rawVariant->normalized_payload, 'price_policy_warning', data_get($rawProduct->normalized_payload, 'price_policy_warning', false)),
                'pricing_policy_type' => data_get($rawVariant->normalized_payload, 'pricing_policy_type', data_get($rawProduct->normalized_payload, 'pricing_policy_type')),
                'supplier_warning_flag' => (bool) data_get($rawVariant->normalized_payload, 'supplier_warning_flag', data_get($rawProduct->normalized_payload, 'supplier_warning_flag', false)),
                'supplier_warning_type' => data_get($rawVariant->normalized_payload, 'supplier_warning_type', data_get($rawProduct->normalized_payload, 'supplier_warning_type')),
                'gallery_images' => data_get($rawVariant->normalized_payload, 'gallery_images', data_get($rawProduct->normalized_payload, 'gallery_images', [])),
                'warnings' => $rawVariant->warnings ?? [],
            ],
            'meta' => [
                'warning_flag' => (bool) ($rawProduct->warning_flag || !empty($rawVariant->warnings)),
                'warnings' => $rawVariant->warnings ?? [],
                'stock_snapshot' => [
                    'stock_quantity' => $rawVariant->variant_stock_quantity,
                    'total_variant_stock_quantity' => data_get($rawVariant->normalized_payload, 'total_variant_stock_quantity', data_get($rawProduct->normalized_payload, 'total_variant_stock_quantity')),
                ],
                'price_snapshot' => [
                    'purchase_price' => $variantPurchasePrice,
                    'list_price' => data_get($rawVariant->normalized_payload, 'list_price', data_get($rawProduct->normalized_payload, 'list_price')),
                    'closed_list_price' => data_get($rawVariant->normalized_payload, 'closed_list_price', data_get($rawProduct->normalized_payload, 'closed_list_price')),
                    'net_price' => data_get($rawVariant->normalized_payload, 'net_price', data_get($rawProduct->normalized_payload, 'net_price')),
                    'discount_rate' => data_get($rawVariant->normalized_payload, 'discount_rate', data_get($rawProduct->normalized_payload, 'discount_rate')),
                    'alternative_price' => data_get($rawVariant->normalized_payload, 'alternative_price', data_get($rawProduct->normalized_payload, 'alternative_price')),
                    'usd_price' => data_get($rawVariant->normalized_payload, 'usd_price', data_get($rawProduct->normalized_payload, 'usd_price')),
                    'net_price_warning' => (bool) data_get($rawVariant->normalized_payload, 'net_price_warning', data_get($rawProduct->normalized_payload, 'net_price_warning', false)),
                    'price_policy_warning' => (bool) data_get($rawVariant->normalized_payload, 'price_policy_warning', data_get($rawProduct->normalized_payload, 'price_policy_warning', false)),
                    'pricing_policy_type' => data_get($rawVariant->normalized_payload, 'pricing_policy_type', data_get($rawProduct->normalized_payload, 'pricing_policy_type')),
                    'supplier_warning_flag' => (bool) data_get($rawVariant->normalized_payload, 'supplier_warning_flag', data_get($rawProduct->normalized_payload, 'supplier_warning_flag', false)),
                    'supplier_warning_type' => data_get($rawVariant->normalized_payload, 'supplier_warning_type', data_get($rawProduct->normalized_payload, 'supplier_warning_type')),
                ],
                'gallery_images' => data_get($rawVariant->normalized_payload, 'gallery_images', data_get($rawProduct->normalized_payload, 'gallery_images', [])),
            ],
        ]);
        $variant->save();

        $rawVariant->forceFill([
            'standard_product_variant_id' => $variant->id,
            'sync_status' => 'processed',
        ])->save();

        return $variant;
    }

    public function updateAggregates(StandardProduct $product): void
    {
        $product->updateAggregateStats();
    }

    public function syncStandardProductImages(StandardProduct $product, SupplierProductRaw $rawProduct): void
    {
        $normalized = $rawProduct->normalized_payload ?? [];
        $primaryImage = $rawProduct->image_url
            ?: data_get($normalized, 'image_url')
            ?: data_get($normalized, 'parent_image_url');
        $galleryImages = collect(data_get($normalized, 'gallery_images', []))
            ->filter()
            ->map(fn ($url) => trim((string) $url))
            ->unique()
            ->values();

        if (filled($primaryImage)) {
            $this->upsertStandardImage([
                'standard_product_id' => $product->id,
                'standard_product_variant_id' => null,
                'image_url' => trim((string) $primaryImage),
                'image_type' => 'main',
            ], [
                'sort_order' => 0,
                'is_primary' => true,
                'fallback_used' => (bool) data_get($normalized, 'image_fallback_used', false),
                'source_supplier_id' => $rawProduct->supplier_id,
                'source_supplier_source_id' => $rawProduct->supplier_source_id,
                'source_raw_product_id' => $rawProduct->id,
                'source_raw_variant_id' => null,
                'meta' => ['scope' => 'product'],
            ]);
        }

        foreach ($galleryImages as $index => $galleryImage) {
            $imageType = $index === 0 && blank($primaryImage) ? 'main' : 'gallery';
            $this->upsertStandardImage([
                'standard_product_id' => $product->id,
                'standard_product_variant_id' => null,
                'image_url' => $galleryImage,
                'image_type' => $imageType,
            ], [
                'sort_order' => $index + 1,
                'is_primary' => blank($primaryImage) && $index === 0,
                'fallback_used' => false,
                'source_supplier_id' => $rawProduct->supplier_id,
                'source_supplier_source_id' => $rawProduct->supplier_source_id,
                'source_raw_product_id' => $rawProduct->id,
                'source_raw_variant_id' => null,
                'meta' => ['scope' => 'product_gallery'],
            ]);
        }
    }

    public function syncStandardVariantImages(StandardProductVariant $variant, SupplierProductVariantRaw $rawVariant): void
    {
        $normalized = $rawVariant->normalized_payload ?? [];
        $variantImage = $rawVariant->variant_image_url ?: data_get($normalized, 'variant_image_url');

        if (blank($variantImage)) {
            return;
        }

        $this->upsertStandardImage([
            'standard_product_id' => $variant->standard_product_id,
            'standard_product_variant_id' => $variant->id,
            'image_url' => trim((string) $variantImage),
            'image_type' => (bool) $rawVariant->image_fallback_used ? 'fallback' : 'variant',
        ], [
            'sort_order' => 0,
            'is_primary' => true,
            'fallback_used' => (bool) $rawVariant->image_fallback_used,
            'source_supplier_id' => $rawVariant->supplier_id,
            'source_supplier_source_id' => $rawVariant->supplier_source_id,
            'source_raw_product_id' => $rawVariant->supplier_product_raw_id,
            'source_raw_variant_id' => $rawVariant->id,
            'meta' => ['scope' => 'variant'],
        ]);
    }

    private function resolvePrefix(SupplierProductRaw $rawProduct): string
    {
        return (string) data_get($rawProduct->normalized_payload, 'supplier_prefix', '')
            ?: (string) data_get($rawProduct->source?->supplier?->config, 'supplier_code_prefix', '')
            ?: (string) optional($rawProduct->supplier)->code;
    }

    private function upsertStandardImage(array $identity, array $values): StandardProductImage
    {
        return StandardProductImage::query()->updateOrCreate($identity, $values);
    }
}
