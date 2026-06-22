<?php

namespace App\Services\ProductDataHub;

use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Models\SupplierSource;
use App\Models\TenantAccount;
use Illuminate\Support\Arr;

class RawProductStagingService
{
    public function stagePreview(SupplierSource $source, array $previewData): array
    {
        $tenantId = $this->resolveTenantId();
        $products = collect($previewData['products'] ?? []);
        $variants = collect($previewData['variants'] ?? []);

        $stagedProducts = 0;
        $stagedVariants = 0;
        $createdProducts = 0;
        $updatedProducts = 0;
        $createdVariants = 0;
        $updatedVariants = 0;
        $productMap = [];
        $warningCount = 0;
        $errorCount = 0;

        foreach ($products as $productData) {
            $product = $this->stageProduct($source, $productData, $tenantId);
            $productMap[$productData['import_hash']] = $product;
            $stagedProducts++;
            $warningCount += count($productData['warnings'] ?? []);
            $errorCount += count($productData['errors'] ?? []);

            if ($product->wasRecentlyCreated) {
                $createdProducts++;
            } else {
                $updatedProducts++;
            }
        }

        foreach ($variants as $variantData) {
            $variant = $this->stageVariant($source, $variantData, $tenantId, $productMap, $products->all());
            $stagedVariants++;
            $warningCount += count($variantData['warnings'] ?? []);
            $errorCount += count($variantData['errors'] ?? []);

            if ($variant->wasRecentlyCreated) {
                $createdVariants++;
            } else {
                $updatedVariants++;
            }
        }

        return [
            'records_read' => (int) data_get($previewData, 'stats.records_read', $products->count()),
            'products' => $stagedProducts,
            'variants' => $stagedVariants,
            'created_products' => $createdProducts,
            'updated_products' => $updatedProducts,
            'created_variants' => $createdVariants,
            'updated_variants' => $updatedVariants,
            'warning_count' => $warningCount,
            'error_count' => $errorCount,
            'skipped_count' => 0,
        ];
    }

    public function stageProduct(SupplierSource $source, array $productData, ?int $tenantId): SupplierProductRaw
    {
        $existingProduct = SupplierProductRaw::query()
            ->where('import_hash', $productData['import_hash'])
            ->first();
        $preservedPayload = $this->preserveProductPayload($existingProduct);
        $resolvedProductName = $productData['product_name']
            ?? $productData['base_product_name']
            ?? $productData['supplier_product_code']
            ?? 'Ham Ürün';
        $resolvedStockQuantity = $productData['stock_quantity']
            ?? data_get($productData, 'normalized_payload.stock_quantity')
            ?? data_get($productData, 'normalized_payload.total_variant_stock_quantity')
            ?? data_get($productData, 'normalized_payload.variant_stock_quantity');
        $resolvedStandardCategoryId = data_get($preservedPayload, 'category_override_standard_category_id')
            ?? $productData['standard_category_id']
            ?? $existingProduct?->standard_category_id;
        $normalizedPayload = array_merge($productData['normalized_payload'] ?? [], [
            'generated_product_code' => $productData['generated_product_code'] ?? null,
            'product_model' => $productData['product_model'] ?? null,
            'image_url' => $productData['image_url'] ?? null,
            'parent_image_url' => $productData['parent_image_url'] ?? null,
            'stock_quantity' => $productData['stock_quantity'] ?? null,
            'total_variant_stock_quantity' => $productData['total_variant_stock_quantity'] ?? null,
            'variant_stock_quantity' => $productData['variant_stock_quantity'] ?? null,
            'gallery_images' => $productData['gallery_images'] ?? [],
            'gallery_source_fields' => $productData['gallery_source_fields'] ?? [],
            'image_source_field' => $productData['image_source_field'] ?? null,
            'image_fallback_used' => (bool) ($productData['image_fallback_used'] ?? false),
            'product_url' => $productData['product_url'] ?? null,
            'detail_url' => $productData['detail_url'] ?? null,
            'purchase_price' => $productData['purchase_price'] ?? null,
            'list_price' => $productData['list_price'] ?? null,
            'usd_price' => $productData['usd_price'] ?? null,
            'closed_list_price' => $productData['closed_list_price'] ?? null,
            'net_price' => $productData['net_price'] ?? null,
            'discount_rate' => $productData['discount_rate'] ?? null,
            'alternative_price' => $productData['alternative_price'] ?? null,
            'vat_rate' => $productData['vat_rate'] ?? null,
            'currency' => $productData['currency'] ?? null,
            'net_price_warning' => (bool) ($productData['net_price_warning'] ?? false),
            'price_policy_warning' => (bool) ($productData['price_policy_warning'] ?? false),
            'pricing_policy_type' => $productData['pricing_policy_type'] ?? null,
            'supplier_warning_flag' => (bool) ($productData['supplier_warning_flag'] ?? false),
            'supplier_warning_type' => $productData['supplier_warning_type'] ?? null,
            'warnings' => $productData['warnings'] ?? [],
            'extracted_color_source' => $productData['extracted_color_source'] ?? null,
        ], $preservedPayload);

        return SupplierProductRaw::query()->updateOrCreate(
            ['import_hash' => $productData['import_hash']],
            [
                'tenant_account_id' => $tenantId,
                'supplier_id' => $source->supplier_id,
                'supplier_source_id' => $source->id,
                'supplier_product_id' => $productData['supplier_product_id'] ?? null,
                'supplier_product_code' => $productData['supplier_product_code'] ?? null,
                'supplier_group_code' => $productData['supplier_group_code'] ?? null,
                'product_name' => $resolvedProductName,
                'supplier_category_name' => $productData['supplier_category_name'] ?? null,
                'standard_category_id' => $resolvedStandardCategoryId,
                'stock_quantity' => $resolvedStockQuantity,
                'purchase_price' => $productData['purchase_price'] ?? null,
                'currency' => $productData['currency'] ?? null,
                'vat_rate' => $productData['vat_rate'] ?? null,
                'image_url' => $productData['image_url'] ?? null,
                'product_url' => $productData['product_url'] ?? null,
                'detail_url' => $productData['detail_url'] ?? null,
                'color' => $productData['color'] ?? null,
                'size' => $productData['size'] ?? null,
                'description' => $productData['description'] ?? null,
                'warning_flag' => (bool) ($productData['warning_flag'] ?? false),
                'raw_payload' => $productData['raw_payload'] ?? null,
                'normalized_payload' => $normalizedPayload,
                'mapping_status' => !empty($resolvedStandardCategoryId) ? 'mapped' : 'pending',
                'warnings' => $productData['warnings'] ?? [],
                'errors' => $productData['errors'] ?? [],
                'sync_status' => 'staged',
                'source_product_id' => $productData['supplier_product_id'] ?? $productData['supplier_product_code'] ?? null,
                'source_sku' => $productData['supplier_product_code'] ?? null,
                'source_name' => $resolvedProductName,
                'source_description' => $productData['description'] ?? null,
                'source_category' => $productData['supplier_category_name'] ?? null,
                'source_price' => $productData['list_price'] ?? $productData['purchase_price'] ?? null,
                'source_currency' => $productData['currency'] ?? null,
                'source_stock' => $resolvedStockQuantity !== null ? (int) round((float) $resolvedStockQuantity) : null,
                'source_attributes' => $productData['raw_payload'] ?? null,
                'error_message' => !empty($productData['errors']) ? implode(' | ', $productData['errors']) : null,
                'synced_at' => now(),
            ]
        );
    }

    public function stageVariant(SupplierSource $source, array $variantData, ?int $tenantId, array $productMap, array $productRows): SupplierProductVariantRaw
    {
        $resolvedVariantStockQuantity = $variantData['variant_stock_quantity']
            ?? data_get($variantData, 'normalized_payload.variant_stock_quantity')
            ?? data_get($variantData, 'normalized_payload.stock_quantity');

        $relatedProduct = collect($productRows)->first(function (array $productRow) use ($variantData) {
            $parentCode = $variantData['parent_supplier_product_id'] ?? null;

            return $parentCode !== null
                && in_array($parentCode, [
                    $productRow['supplier_product_id'] ?? null,
                    $productRow['supplier_group_code'] ?? null,
                    $productRow['supplier_product_code'] ?? null,
                ], true);
        });

        $rawProduct = $relatedProduct
            ? ($productMap[$relatedProduct['import_hash']] ?? null)
            : null;

        return SupplierProductVariantRaw::query()->updateOrCreate(
            ['import_hash' => $variantData['import_hash']],
            [
                'tenant_account_id' => $tenantId,
                'supplier_id' => $source->supplier_id,
                'supplier_source_id' => $source->id,
                'supplier_product_raw_id' => $rawProduct?->id,
                'parent_supplier_product_id' => $variantData['parent_supplier_product_id'] ?? null,
                'supplier_group_code' => $variantData['supplier_group_code'] ?? null,
                'variant_id' => $variantData['variant_id'] ?? null,
                'variant_code' => $variantData['variant_code'] ?? null,
                'variant_stock_code' => $variantData['variant_stock_code'] ?? null,
                'variant_name' => $variantData['variant_name'] ?? null,
                'variant_color' => $variantData['variant_color'] ?? null,
                'variant_size' => $variantData['variant_size'] ?? null,
                'variant_attributes' => $variantData['variant_attributes'] ?? null,
                'variant_stock_quantity' => $resolvedVariantStockQuantity,
                'variant_image_url' => $variantData['variant_image_url'] ?? null,
                'parent_image_url' => $variantData['parent_image_url'] ?? null,
                'image_fallback_used' => (bool) ($variantData['image_fallback_used'] ?? false),
                'generated_variant_code' => $variantData['generated_variant_code'] ?? null,
                'raw_payload' => $variantData['raw_payload'] ?? null,
                'normalized_payload' => array_merge($variantData['normalized_payload'] ?? [], [
                    'generated_variant_code' => $variantData['generated_variant_code'] ?? null,
                    'variant_image_url' => $variantData['variant_image_url'] ?? null,
                    'parent_image_url' => $variantData['parent_image_url'] ?? null,
                    'stock_quantity' => $variantData['stock_quantity'] ?? null,
                    'variant_stock_quantity' => $variantData['variant_stock_quantity'] ?? null,
                    'total_variant_stock_quantity' => $variantData['total_variant_stock_quantity'] ?? null,
                    'gallery_images' => $variantData['gallery_images'] ?? [],
                    'gallery_source_fields' => $variantData['gallery_source_fields'] ?? [],
                    'image_fallback_used' => (bool) ($variantData['image_fallback_used'] ?? false),
                    'variant_image_source_field' => $variantData['variant_image_source_field'] ?? null,
                    'purchase_price' => $variantData['purchase_price'] ?? null,
                    'list_price' => $variantData['list_price'] ?? null,
                    'usd_price' => $variantData['usd_price'] ?? null,
                    'closed_list_price' => $variantData['closed_list_price'] ?? null,
                    'net_price' => $variantData['net_price'] ?? null,
                    'discount_rate' => $variantData['discount_rate'] ?? null,
                    'alternative_price' => $variantData['alternative_price'] ?? null,
                    'vat_rate' => $variantData['vat_rate'] ?? null,
                    'currency' => $variantData['currency'] ?? null,
                    'net_price_warning' => (bool) ($variantData['net_price_warning'] ?? false),
                    'price_policy_warning' => (bool) ($variantData['price_policy_warning'] ?? false),
                    'pricing_policy_type' => $variantData['pricing_policy_type'] ?? null,
                    'supplier_warning_flag' => (bool) ($variantData['supplier_warning_flag'] ?? false),
                    'supplier_warning_type' => $variantData['supplier_warning_type'] ?? null,
                    'warnings' => $variantData['warnings'] ?? [],
                    'extracted_color_source' => $variantData['extracted_color_source'] ?? null,
                ]),
                'warnings' => $variantData['warnings'] ?? [],
                'errors' => $variantData['errors'] ?? [],
                'sync_status' => 'staged',
            ]
        );
    }

    private function preserveProductPayload(?SupplierProductRaw $existingProduct): array
    {
        if (!$existingProduct) {
            return [];
        }

        $existingPayload = (array) ($existingProduct->normalized_payload ?? []);

        return Arr::whereNotNull([
            '_sync_meta' => $existingPayload['_sync_meta'] ?? null,
            'category_override_standard_category_id' => $existingPayload['category_override_standard_category_id'] ?? null,
            'category_override_name' => $existingPayload['category_override_name'] ?? null,
            'category_override_note' => $existingPayload['category_override_note'] ?? null,
            'category_override_apply_to_rule' => $existingPayload['category_override_apply_to_rule'] ?? null,
            'category_override_applied_at' => $existingPayload['category_override_applied_at'] ?? null,
        ]);
    }

    private function resolveTenantId(): ?int
    {
        $tenant = request()->attributes->get('current_tenant');

        if ($tenant instanceof TenantAccount) {
            return $tenant->id;
        }

        return auth()->user()?->tenantAccount?->id
            ?? TenantAccount::query()->where('panel_subdomain', 'demo')->value('id')
            ?? TenantAccount::query()->orderBy('id')->value('id');
    }
}
