<?php

namespace App\Services\ProductDataHub;

use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;

class DeltaSyncHashService
{
    public function buildProductHashes(array $product, array $variants = []): array
    {
        return [
            'identity_hash' => $this->hashValue([
                'product_id' => $this->cleanText($product['supplier_product_id'] ?? null),
                'product_code' => $this->cleanText($product['supplier_product_code'] ?? null),
                'group_code' => $this->cleanText($product['supplier_group_code'] ?? null),
            ]),
            'content_hash' => $this->hashValue([
                'product_name' => $this->cleanText($product['product_name'] ?? null),
                'description' => $this->cleanText($product['description'] ?? null),
                'brand' => $this->cleanText($product['brand'] ?? data_get($product, 'normalized_payload.brand')),
                'material' => $this->cleanText($product['material'] ?? data_get($product, 'normalized_payload.material')),
                'dimensions' => $this->cleanText($product['dimensions'] ?? data_get($product, 'normalized_payload.dimensions')),
                'attributes' => $this->normalizeArray($product['attributes'] ?? data_get($product, 'normalized_payload.attributes') ?? []),
            ]),
            'price_hash' => $this->hashValue([
                'list_price' => $this->normalizeDecimal($product['list_price'] ?? data_get($product, 'normalized_payload.list_price')),
                'net_price' => $this->normalizeDecimal($product['net_price'] ?? data_get($product, 'normalized_payload.net_price')),
                'discount_rate' => $this->normalizeDecimal($product['discount_rate'] ?? data_get($product, 'normalized_payload.discount_rate')),
                'currency' => $this->cleanText($product['currency'] ?? data_get($product, 'normalized_payload.currency')),
                'vat_rate' => $this->normalizeDecimal($product['vat_rate'] ?? data_get($product, 'normalized_payload.vat_rate')),
                'pricing_policy_type' => $this->cleanText($product['pricing_policy_type'] ?? data_get($product, 'normalized_payload.pricing_policy_type')),
            ]),
            'stock_hash' => $this->hashValue([
                'stock_quantity' => $this->normalizeDecimal(
                    $product['stock_quantity']
                        ?? data_get($product, 'normalized_payload.stock_quantity')
                        ?? data_get($product, 'normalized_payload.total_variant_stock_quantity')
                ),
                'stock_status' => $this->cleanText($product['stock_status'] ?? data_get($product, 'normalized_payload.stock_status')),
                'stock_unit' => $this->cleanText($product['stock_unit'] ?? data_get($product, 'normalized_payload.stock_unit')),
                'supplier_stock_code' => $this->cleanText($product['supplier_stock_code'] ?? $product['supplier_product_code'] ?? null),
            ]),
            'image_hash' => $this->hashValue([
                'main_image_url' => $this->cleanUrl($product['image_url'] ?? data_get($product, 'normalized_payload.image_url')),
                'gallery_urls' => $this->normalizeList($product['gallery_images'] ?? data_get($product, 'normalized_payload.gallery_images') ?? []),
                'image_count' => count($this->normalizeList($product['gallery_images'] ?? data_get($product, 'normalized_payload.gallery_images') ?? [])),
            ]),
            'category_hash' => $this->hashValue([
                'original_category' => $this->cleanText($product['supplier_category_name'] ?? data_get($product, 'normalized_payload.supplier_category_name')),
                'original_category_code' => $this->cleanText($product['supplier_category_id'] ?? data_get($product, 'normalized_payload.supplier_category_id')),
                'mapped_standard_category_id' => $this->cleanText($product['standard_category_id'] ?? data_get($product, 'normalized_payload.category_override_standard_category_id')),
            ]),
            'variant_structure_hash' => $this->buildVariantStructureHash($variants),
        ];
    }

    public function buildVariantHashes(array $variant): array
    {
        return [
            'identity_hash' => $this->hashValue([
                'variant_id' => $this->cleanText($variant['variant_id'] ?? null),
                'variant_stock_code' => $this->cleanText($variant['variant_stock_code'] ?? null),
                'variant_code' => $this->cleanText($variant['variant_code'] ?? null),
                'group_code' => $this->cleanText($variant['supplier_group_code'] ?? null),
                'parent_supplier_product_id' => $this->cleanText($variant['parent_supplier_product_id'] ?? null),
            ]),
            'content_hash' => $this->hashValue([
                'variant_name' => $this->cleanText($variant['variant_name'] ?? null),
                'variant_color' => $this->cleanText($variant['variant_color'] ?? null),
                'variant_size' => $this->cleanText($variant['variant_size'] ?? null),
                'variant_attributes' => $this->normalizeArray($variant['variant_attributes'] ?? []),
            ]),
            'price_hash' => $this->hashValue([
                'list_price' => $this->normalizeDecimal($variant['list_price'] ?? data_get($variant, 'normalized_payload.list_price')),
                'net_price' => $this->normalizeDecimal($variant['net_price'] ?? data_get($variant, 'normalized_payload.net_price')),
                'discount_rate' => $this->normalizeDecimal($variant['discount_rate'] ?? data_get($variant, 'normalized_payload.discount_rate')),
                'currency' => $this->cleanText($variant['currency'] ?? data_get($variant, 'normalized_payload.currency')),
                'vat_rate' => $this->normalizeDecimal($variant['vat_rate'] ?? data_get($variant, 'normalized_payload.vat_rate')),
                'pricing_policy_type' => $this->cleanText($variant['pricing_policy_type'] ?? data_get($variant, 'normalized_payload.pricing_policy_type')),
            ]),
            'stock_hash' => $this->hashValue([
                'stock_quantity' => $this->normalizeDecimal($variant['variant_stock_quantity'] ?? data_get($variant, 'normalized_payload.variant_stock_quantity')),
                'stock_status' => $this->cleanText($variant['stock_status'] ?? data_get($variant, 'normalized_payload.stock_status')),
                'stock_unit' => $this->cleanText($variant['stock_unit'] ?? data_get($variant, 'normalized_payload.stock_unit')),
                'supplier_stock_code' => $this->cleanText($variant['variant_stock_code'] ?? null),
            ]),
            'image_hash' => $this->hashValue([
                'main_image_url' => $this->cleanUrl($variant['variant_image_url'] ?? data_get($variant, 'normalized_payload.variant_image_url')),
                'gallery_urls' => $this->normalizeList($variant['gallery_images'] ?? data_get($variant, 'normalized_payload.gallery_images') ?? []),
                'image_count' => count($this->normalizeList($variant['gallery_images'] ?? data_get($variant, 'normalized_payload.gallery_images') ?? [])),
            ]),
            'category_hash' => $this->hashValue([
                'original_category' => $this->cleanText($variant['supplier_category_name'] ?? data_get($variant, 'normalized_payload.supplier_category_name')),
                'original_category_code' => $this->cleanText($variant['supplier_category_id'] ?? data_get($variant, 'normalized_payload.supplier_category_id')),
                'mapped_standard_category_id' => $this->cleanText($variant['standard_category_id'] ?? data_get($variant, 'normalized_payload.category_override_standard_category_id')),
            ]),
        ];
    }

    public function buildHashesFromRawProduct(SupplierProductRaw $product, array $variants = []): array
    {
        return $this->buildProductHashes([
            'supplier_product_id' => $product->supplier_product_id,
            'supplier_product_code' => $product->supplier_product_code,
            'supplier_group_code' => $product->supplier_group_code,
            'product_name' => $product->product_name,
            'supplier_category_name' => $product->supplier_category_name,
            'standard_category_id' => $product->standard_category_id,
            'stock_quantity' => $product->stock_quantity,
            'image_url' => $product->image_url,
            'description' => $product->description,
            'currency' => $product->currency,
            'vat_rate' => $product->vat_rate,
            'normalized_payload' => $product->normalized_payload ?? [],
        ], $variants);
    }

    public function buildHashesFromRawVariant(SupplierProductVariantRaw $variant): array
    {
        return $this->buildVariantHashes([
            'parent_supplier_product_id' => $variant->parent_supplier_product_id,
            'supplier_group_code' => $variant->supplier_group_code,
            'variant_id' => $variant->variant_id,
            'variant_code' => $variant->variant_code,
            'variant_stock_code' => $variant->variant_stock_code,
            'variant_name' => $variant->variant_name,
            'variant_color' => $variant->variant_color,
            'variant_size' => $variant->variant_size,
            'variant_attributes' => $variant->variant_attributes ?? [],
            'variant_stock_quantity' => $variant->variant_stock_quantity,
            'variant_image_url' => $variant->variant_image_url,
            'normalized_payload' => $variant->normalized_payload ?? [],
        ]);
    }

    public function hasReliableProductIdentity(array $product): bool
    {
        return filled($this->cleanText($product['supplier_product_id'] ?? null))
            || filled($this->cleanText($product['supplier_product_code'] ?? null))
            || filled($this->cleanText($product['supplier_group_code'] ?? null));
    }

    public function hasReliableVariantIdentity(array $variant): bool
    {
        return filled($this->cleanText($variant['variant_id'] ?? null))
            || filled($this->cleanText($variant['variant_stock_code'] ?? null))
            || (
                filled($this->cleanText($variant['supplier_group_code'] ?? null))
                && filled($this->cleanText($variant['variant_stock_code'] ?? null))
            );
    }

    public function productIdentityKey(array $product): ?string
    {
        $productId = $this->cleanText($product['supplier_product_id'] ?? null);
        if (filled($productId)) {
            return 'product:' . $productId;
        }

        $productCode = $this->cleanText($product['supplier_product_code'] ?? null);
        if (filled($productCode)) {
            return 'code:' . $productCode;
        }

        $groupCode = $this->cleanText($product['supplier_group_code'] ?? null);
        if (filled($groupCode)) {
            return 'group:' . $groupCode;
        }

        return null;
    }

    public function variantIdentityKey(array $variant): ?string
    {
        $variantId = $this->cleanText($variant['variant_id'] ?? null);
        if (filled($variantId)) {
            return 'variant:' . $variantId;
        }

        $variantStockCode = $this->cleanText($variant['variant_stock_code'] ?? null);
        $groupCode = $this->cleanText($variant['supplier_group_code'] ?? null);
        if (filled($groupCode) && filled($variantStockCode)) {
            return 'group-stock:' . $groupCode . ':' . $variantStockCode;
        }

        if (filled($variantStockCode)) {
            return 'stock:' . $variantStockCode;
        }

        return null;
    }

    public function buildVariantStructureHash(array $variants): ?string
    {
        $signatures = collect($variants)
            ->filter(fn ($variant) => is_array($variant))
            ->map(fn (array $variant) => [
                'variant_code' => $this->cleanText($variant['variant_stock_code'] ?? $variant['variant_code'] ?? $variant['generated_variant_code'] ?? null),
                'color' => $this->cleanText($variant['variant_color'] ?? data_get($variant, 'normalized_payload.variant_color')),
                'size' => $this->cleanText($variant['variant_size'] ?? data_get($variant, 'normalized_payload.variant_size')),
                'option_signature' => $this->normalizeArray($variant['variant_attributes'] ?? data_get($variant, 'normalized_payload.variant_attributes') ?? []),
            ])
            ->sortBy(fn (array $signature) => json_encode($signature, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->values()
            ->all();

        if ($signatures === []) {
            return null;
        }

        return $this->hashValue($signatures);
    }

    private function hashValue(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function normalizeDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));

        if (!is_numeric($normalized)) {
            return $this->cleanText((string) $value);
        }

        return number_format((float) $normalized, 4, '.', '');
    }

    private function normalizeList(array $values): array
    {
        return collect($values)
            ->map(fn ($value) => $this->cleanUrl($value))
            ->filter(fn ($value) => filled($value))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function normalizeArray(array $value): array
    {
        return $this->sortRecursive(
            collect($value)
                ->map(fn ($item) => is_string($item) ? trim($item) : $item)
                ->all()
        );
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $normalized = array_map(fn ($item) => $this->sortRecursive($item), $value);
            usort($normalized, fn ($left, $right) => strcmp(
                json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ));

            return $normalized;
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursive($item);
        }

        return $value;
    }

    private function cleanText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim($value);

        return $clean === '' ? null : $clean;
    }

    private function cleanUrl(?string $value): ?string
    {
        return $this->cleanText($value);
    }
}
