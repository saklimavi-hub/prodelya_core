<?php

namespace App\Services\ProductDataHub;

use App\Models\SupplierProductRaw;
use App\Models\SupplierProductVariantRaw;
use App\Models\SupplierSource;
use App\Services\ProductFieldDictionaryService;
use Illuminate\Support\Collection;

class DeltaChangeDetectorService
{
    public function __construct(
        private readonly DeltaSyncHashService $hashService,
        private readonly ProductFieldDictionaryService $fieldDictionary,
    ) {
    }

    public function detectForSource(
        SupplierSource $source,
        array $previewData,
        Collection $existingProducts,
        Collection $existingVariants
    ): array {
        $productRows = collect($previewData['products'] ?? [])->values();
        $variantRows = collect($previewData['variants'] ?? [])->values();

        $existingProductMap = $existingProducts
            ->mapWithKeys(fn (SupplierProductRaw $product) => array_filter([
                $this->hashService->productIdentityKey([
                    'supplier_product_id' => $product->supplier_product_id,
                    'supplier_product_code' => $product->supplier_product_code,
                    'supplier_group_code' => $product->supplier_group_code,
                ]) => $product,
            ]));

        $existingVariantMap = $existingVariants
            ->mapWithKeys(fn (SupplierProductVariantRaw $variant) => array_filter([
                $this->hashService->variantIdentityKey([
                    'variant_id' => $variant->variant_id,
                    'variant_stock_code' => $variant->variant_stock_code,
                    'variant_code' => $variant->variant_code,
                    'supplier_group_code' => $variant->supplier_group_code,
                ]) => $variant,
            ]));

        $changes = [];
        $counts = $this->emptyCounts();
        $seenProductKeys = [];
        $seenVariantKeys = [];
        $priceJumps = [];

        $identitySummary = $this->buildIdentitySummary($source, $productRows, $variantRows);

        foreach ($productRows as $productRow) {
            $productKey = $this->hashService->productIdentityKey($productRow);

            if (!$this->hashService->hasReliableProductIdentity($productRow)) {
                $changes[] = $this->makeChange('blocked_identity_missing', 'product', null, [
                    'product_name' => $productRow['product_name'] ?? null,
                    'supplier_product_code' => $productRow['supplier_product_code'] ?? null,
                ], null, 'Ürün identity güvenilir değil; delta apply adayı sayılamaz.');
                $counts['blocked_identity_missing']++;
                continue;
            }

            $seenProductKeys[] = $productKey;
            $existingProduct = $existingProductMap[$productKey] ?? null;
            $currentVariants = $this->resolveCurrentVariantsForProduct($productRow, $variantRows);
            $currentHashes = $this->hashService->buildProductHashes($productRow, $currentVariants);

            if (!$existingProduct) {
                $changes[] = $this->makeChange('new_product', 'product', $productKey, null, [
                    'product_name' => $productRow['product_name'] ?? null,
                ], 'Kaynakta yeni ürün tespit edildi.');
                $counts['new_product']++;
                continue;
            }

            $existingHashes = $this->resolveExistingProductHashes($existingProduct, $this->resolveExistingVariantsForProduct($existingProduct, $existingVariants));
            $productChanges = $this->compareHashGroups($productKey, $existingHashes, $currentHashes, $productRow, $existingProduct);

            foreach ($productChanges['changes'] as $change) {
                $changes[] = $change;
                $counts[$change['type']]++;
                if ($change['type'] === 'suspicious_price_jump') {
                    $priceJumps[] = $change;
                }
            }
        }

        foreach ($variantRows as $variantRow) {
            $variantKey = $this->hashService->variantIdentityKey($variantRow);

            if (!$this->hashService->hasReliableVariantIdentity($variantRow)) {
                $changes[] = $this->makeChange('blocked_identity_missing', 'variant', null, [
                    'variant_name' => $variantRow['variant_name'] ?? null,
                    'variant_stock_code' => $variantRow['variant_stock_code'] ?? null,
                ], null, 'Varyant identity güvenilir değil; delta apply adayı sayılamaz.');
                $counts['blocked_identity_missing']++;
                continue;
            }

            $seenVariantKeys[] = $variantKey;
            $existingVariant = $existingVariantMap[$variantKey] ?? null;
            $currentHashes = $this->hashService->buildVariantHashes($variantRow);

            if (!$existingVariant) {
                $changes[] = $this->makeChange('new_variant', 'variant', $variantKey, null, [
                    'variant_name' => $variantRow['variant_name'] ?? null,
                ], 'Kaynakta yeni varyant tespit edildi.');
                $counts['new_variant']++;
                continue;
            }

            $existingHashes = $this->resolveExistingVariantHashes($existingVariant);
            $variantChanges = $this->compareHashGroups($variantKey, $existingHashes, $currentHashes, $variantRow, $existingVariant, true);

            foreach ($variantChanges['changes'] as $change) {
                $changes[] = $change;
                $counts[$change['type']]++;
                if ($change['type'] === 'suspicious_price_jump') {
                    $priceJumps[] = $change;
                }
            }
        }

        foreach ($existingProductMap as $productKey => $existingProduct) {
            if (!in_array($productKey, $seenProductKeys, true)) {
                $changes[] = $this->makeChange('missing_product', 'product', $productKey, [
                    'product_name' => $existingProduct->product_name,
                ], null, 'Ürün son beslemede görünmedi.');
                $counts['missing_product']++;
            }
        }

        foreach ($existingVariantMap as $variantKey => $existingVariant) {
            if (!in_array($variantKey, $seenVariantKeys, true)) {
                $changes[] = $this->makeChange('missing_variant', 'variant', $variantKey, [
                    'variant_name' => $existingVariant->variant_name,
                    'variant_stock_code' => $existingVariant->variant_stock_code,
                ], null, 'Varyant son beslemede görünmedi.');
                $counts['missing_variant']++;
            }
        }

        $currentProductCount = $productRows->count();
        $existingProductCount = $existingProducts->count();
        $missingRatio = $existingProductCount > 0 ? ($counts['missing_product'] / max(1, $existingProductCount)) : 0.0;

        if ($existingProductCount > 0 && $currentProductCount <= (int) floor($existingProductCount * 0.5)) {
            $changes[] = $this->makeChange('suspicious_feed_drop', 'source', (string) $source->id, [
                'existing_product_count' => $existingProductCount,
            ], [
                'current_product_count' => $currentProductCount,
            ], 'Feed ürün sayısı önceki başarılı duruma göre anlamlı şekilde düştü.');
            $counts['suspicious_feed_drop']++;
        }

        if ($missingRatio >= 0.2) {
            $changes[] = $this->makeChange('suspicious_feed_drop', 'source', (string) $source->id, [
                'missing_ratio' => round($missingRatio, 4),
            ], [
                'missing_product_count' => $counts['missing_product'],
            ], 'Kaynakta görünmeyen ürün oranı yüksek, otomatik apply güvenli değil.');
            $counts['suspicious_feed_drop']++;
        }

        if ($existingProductCount > 0 && $currentProductCount < max(1, (int) ceil($existingProductCount * 0.1))) {
            $changes[] = $this->makeChange('feed_degraded', 'source', (string) $source->id, [
                'existing_product_count' => $existingProductCount,
            ], [
                'current_product_count' => $currentProductCount,
            ], 'Parse edilen kayıt sayısı çok düşük; feed bozulmuş olabilir.');
            $counts['feed_degraded']++;
        }

        return [
            'changes' => $changes,
            'counts' => array_filter($counts),
            'identity_summary' => $identitySummary,
            'flags' => [
                'feed_degraded' => ($counts['feed_degraded'] ?? 0) > 0,
                'suspicious_feed_drop' => ($counts['suspicious_feed_drop'] ?? 0) > 0,
                'suspicious_price_jump' => ($counts['suspicious_price_jump'] ?? 0) > 0,
            ],
            'apply_candidate' => $identitySummary['reliable'] && ($counts['blocked_identity_missing'] ?? 0) === 0,
        ];
    }

    private function buildIdentitySummary(SupplierSource $source, Collection $products, Collection $variants): array
    {
        $productReliableCount = $products->filter(fn (array $row) => $this->hashService->hasReliableProductIdentity($row))->count();
        $variantReliableCount = $variants->filter(fn (array $row) => $this->hashService->hasReliableVariantIdentity($row))->count();
        $mappingStatus = $this->resolveUpdateKeyMappingStatus($source);
        $reliable = $mappingStatus['ok']
            && $productReliableCount === $products->count()
            && $variantReliableCount === $variants->count();

        return [
            'reliable' => $reliable,
            'status' => $reliable ? 'reliable' : 'risky',
            'label' => $reliable ? 'Güvenilir' : 'Riskli',
            'product_total' => $products->count(),
            'product_reliable' => $productReliableCount,
            'variant_total' => $variants->count(),
            'variant_reliable' => $variantReliableCount,
            'mapping_ok' => $mappingStatus['ok'],
            'warnings' => array_values(array_filter([
                $mappingStatus['message'],
                $productReliableCount !== $products->count() ? 'Bazı ürünlerde güvenilir delta identity bulunamadı.' : null,
                $variantReliableCount !== $variants->count() ? 'Bazı varyantlarda güvenilir delta identity bulunamadı.' : null,
            ])),
        ];
    }

    private function resolveUpdateKeyMappingStatus(SupplierSource $source): array
    {
        $profileKey = (string) data_get($source->config, 'profile_key', '');
        if ($profileKey !== 'CUSTOM') {
            return ['ok' => true, 'message' => null];
        }

        $updateKeyDefinition = collect($this->fieldDictionary->getRequiredMappingDefinitions())
            ->firstWhere('key', 'update_key');
        $acceptedFields = (array) ($updateKeyDefinition['accepted_fields'] ?? []);
        $selected = $source->relationLoaded('fieldMappings')
            ? $source->fieldMappings->pluck('target_field')->filter()->all()
            : $source->fieldMappings()->pluck('target_field')->filter()->all();
        $hasAcceptedField = collect($selected)->contains(fn ($field) => in_array($field, $acceptedFields, true));

        return [
            'ok' => $hasAcceptedField,
            'message' => $hasAcceptedField ? null : 'Güncelleme Anahtarı eşlemesi eksik; delta apply ileride engellenmeli.',
        ];
    }

    private function compareHashGroups(
        string $identityKey,
        array $existingHashes,
        array $currentHashes,
        array $currentRow,
        SupplierProductRaw|SupplierProductVariantRaw $existingRow,
        bool $variantScope = false
    ): array {
        $changes = [];

        $priceChanged = ($existingHashes['price_hash'] ?? null) !== ($currentHashes['price_hash'] ?? null);
        $stockChanged = ($existingHashes['stock_hash'] ?? null) !== ($currentHashes['stock_hash'] ?? null);

        if ($priceChanged && $stockChanged) {
            $changes[] = $this->makeChange('price_and_stock_changed', $variantScope ? 'variant' : 'product', $identityKey, [
                'price_hash' => $existingHashes['price_hash'] ?? null,
                'stock_hash' => $existingHashes['stock_hash'] ?? null,
            ], [
                'price_hash' => $currentHashes['price_hash'] ?? null,
                'stock_hash' => $currentHashes['stock_hash'] ?? null,
            ], 'Fiyat ve stok birlikte değişti.');
        } elseif ($priceChanged) {
            $changes[] = $this->makeChange('price_changed', $variantScope ? 'variant' : 'product', $identityKey, [
                'price_hash' => $existingHashes['price_hash'] ?? null,
            ], [
                'price_hash' => $currentHashes['price_hash'] ?? null,
            ], 'Fiyat bilgisi değişti.');
        } elseif ($stockChanged) {
            $changes[] = $this->makeChange('stock_changed', $variantScope ? 'variant' : 'product', $identityKey, [
                'stock_hash' => $existingHashes['stock_hash'] ?? null,
            ], [
                'stock_hash' => $currentHashes['stock_hash'] ?? null,
            ], 'Stok bilgisi değişti.');
        }

        if (($existingHashes['image_hash'] ?? null) !== ($currentHashes['image_hash'] ?? null)) {
            $changes[] = $this->makeChange('image_changed', $variantScope ? 'variant' : 'product', $identityKey, null, null, 'Görsel bilgisi değişti.');
        }

        if (($existingHashes['category_hash'] ?? null) !== ($currentHashes['category_hash'] ?? null)) {
            $changes[] = $this->makeChange('category_changed', $variantScope ? 'variant' : 'product', $identityKey, null, null, 'Kategori bilgisi değişti.');
        }

        if (($existingHashes['content_hash'] ?? null) !== ($currentHashes['content_hash'] ?? null)) {
            $changes[] = $this->makeChange('content_changed', $variantScope ? 'variant' : 'product', $identityKey, null, null, 'İçerik bilgisi değişti.');
        }

        if (!$variantScope && ($existingHashes['variant_structure_hash'] ?? null) !== ($currentHashes['variant_structure_hash'] ?? null)) {
            $changes[] = $this->makeChange('variant_structure_changed', 'product', $identityKey, null, null, 'Varyant yapısı değişti.');
        }

        if ($this->hasBlockedRequiredField($currentRow, $variantScope)) {
            $changes[] = $this->makeChange('blocked_required_field_missing', $variantScope ? 'variant' : 'product', $identityKey, null, null, 'Zorunlu alan eksik veya bozuldu.');
        }

        $priceJump = $this->detectPriceJump($existingRow, $currentRow);
        if ($priceJump !== null) {
            $changes[] = $this->makeChange('suspicious_price_jump', $variantScope ? 'variant' : 'product', $identityKey, [
                'old_list_price' => $priceJump['old'],
            ], [
                'new_list_price' => $priceJump['new'],
                'change_ratio' => $priceJump['ratio'],
            ], 'Fiyat değişimi güvenlik eşiğini aştı.');
        }

        return ['changes' => $changes];
    }

    private function detectPriceJump(SupplierProductRaw|SupplierProductVariantRaw $existingRow, array $currentRow): ?array
    {
        $previous = (float) (data_get($existingRow->normalized_payload, 'list_price') ?? 0);
        $current = (float) ($currentRow['list_price'] ?? data_get($currentRow, 'normalized_payload.list_price') ?? 0);
        $previousCurrency = (string) (data_get($existingRow->normalized_payload, 'currency') ?? '');
        $currentCurrency = (string) ($currentRow['currency'] ?? data_get($currentRow, 'normalized_payload.currency') ?? '');

        if ($previous <= 0 || $current <= 0) {
            return $previousCurrency !== $currentCurrency && $previousCurrency !== '' && $currentCurrency !== ''
                ? ['old' => $previous, 'new' => $current, 'ratio' => null]
                : null;
        }

        $ratio = (($current - $previous) / $previous) * 100;

        if (abs($ratio) < 100 && ($previousCurrency === '' || $currentCurrency === '' || $previousCurrency === $currentCurrency)) {
            return null;
        }

        return [
            'old' => $previous,
            'new' => $current,
            'ratio' => round($ratio, 2),
        ];
    }

    private function hasBlockedRequiredField(array $row, bool $variantScope): bool
    {
        if ($variantScope) {
            return blank($row['variant_name'] ?? null)
                || blank($row['variant_stock_code'] ?? $row['variant_code'] ?? $row['variant_id'] ?? null);
        }

        return blank($row['product_name'] ?? null)
            || (
                blank($row['list_price'] ?? null)
                && blank($row['purchase_price'] ?? null)
                && blank(data_get($row, 'normalized_payload.list_price'))
            );
    }

    private function resolveExistingProductHashes(SupplierProductRaw $product, array $existingVariants): array
    {
        $computed = $this->hashService->buildHashesFromRawProduct(
            $product,
            array_map(fn (SupplierProductVariantRaw $variant) => [
                'variant_id' => $variant->variant_id,
                'variant_code' => $variant->variant_code,
                'variant_stock_code' => $variant->variant_stock_code,
                'variant_color' => $variant->variant_color,
                'variant_size' => $variant->variant_size,
                'variant_attributes' => $variant->variant_attributes ?? [],
                'generated_variant_code' => $variant->generated_variant_code,
                'normalized_payload' => $variant->normalized_payload ?? [],
            ], $existingVariants)
        );

        return [
            'identity_hash' => $product->identity_hash ?: $computed['identity_hash'],
            'content_hash' => $product->content_hash ?: $computed['content_hash'],
            'price_hash' => $product->price_hash ?: $computed['price_hash'],
            'stock_hash' => $product->stock_hash ?: $computed['stock_hash'],
            'image_hash' => $product->image_hash ?: $computed['image_hash'],
            'category_hash' => $product->category_hash ?: $computed['category_hash'],
            'variant_structure_hash' => $product->variant_structure_hash ?: $computed['variant_structure_hash'],
        ];
    }

    private function resolveExistingVariantHashes(SupplierProductVariantRaw $variant): array
    {
        $computed = $this->hashService->buildHashesFromRawVariant($variant);

        return [
            'identity_hash' => $variant->identity_hash ?: $computed['identity_hash'],
            'content_hash' => $variant->content_hash ?: $computed['content_hash'],
            'price_hash' => $variant->price_hash ?: $computed['price_hash'],
            'stock_hash' => $variant->stock_hash ?: $computed['stock_hash'],
            'image_hash' => $variant->image_hash ?: $computed['image_hash'],
            'category_hash' => $variant->category_hash ?: $computed['category_hash'],
        ];
    }

    private function resolveCurrentVariantsForProduct(array $productRow, Collection $variantRows): array
    {
        $candidateKeys = array_filter([
            trim((string) ($productRow['supplier_product_id'] ?? '')) ?: null,
            trim((string) ($productRow['supplier_product_code'] ?? '')) ?: null,
            trim((string) ($productRow['supplier_group_code'] ?? '')) ?: null,
        ]);

        return $variantRows
            ->filter(function (array $variant) use ($candidateKeys) {
                return collect([
                    $variant['parent_supplier_product_id'] ?? null,
                    $variant['supplier_group_code'] ?? null,
                    $variant['variant_code'] ?? null,
                ])->filter()->intersect($candidateKeys)->isNotEmpty();
            })
            ->values()
            ->all();
    }

    private function resolveExistingVariantsForProduct(SupplierProductRaw $product, Collection $existingVariants): array
    {
        return $existingVariants
            ->filter(function (SupplierProductVariantRaw $variant) use ($product) {
                return $variant->supplier_product_raw_id === $product->id
                    || collect([
                        $variant->parent_supplier_product_id,
                        $variant->supplier_group_code,
                    ])->filter()->intersect(array_filter([
                        $product->supplier_product_id,
                        $product->supplier_product_code,
                        $product->supplier_group_code,
                    ]))->isNotEmpty();
            })
            ->values()
            ->all();
    }

    private function makeChange(string $type, string $scope, ?string $identityKey, ?array $oldValue, ?array $newValue, string $message): array
    {
        return [
            'type' => $type,
            'scope' => $scope,
            'identity_key' => $identityKey,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'message' => $message,
        ];
    }

    private function emptyCounts(): array
    {
        return [
            'price_changed' => 0,
            'stock_changed' => 0,
            'price_and_stock_changed' => 0,
            'new_product' => 0,
            'new_variant' => 0,
            'missing_product' => 0,
            'missing_variant' => 0,
            'category_changed' => 0,
            'image_changed' => 0,
            'content_changed' => 0,
            'variant_structure_changed' => 0,
            'blocked_identity_missing' => 0,
            'blocked_required_field_missing' => 0,
            'feed_degraded' => 0,
            'suspicious_price_jump' => 0,
            'suspicious_feed_drop' => 0,
        ];
    }
}
