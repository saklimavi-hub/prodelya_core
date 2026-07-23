<?php

namespace App\Services\ProductDataHub;

use App\Models\SupplierCategoryMapping;
use App\Models\SupplierFieldMapping;
use App\Models\SupplierSource;
use App\Services\ProductCodeNormalizerService;
use App\Services\ProductFieldDictionaryService;

class PreviewParserService
{
    public function __construct(
        private readonly ProductFieldDictionaryService $fieldDictionary,
        private readonly ProductCodeNormalizerService $productCodeNormalizer,
        private readonly ProductPageGalleryEnrichmentService $productPageGalleryEnrichment,
        private readonly ProductAttributeValueNormalizer $attributeValueNormalizer,
    ) {
    }

    private bool $allowGalleryEnrichment = false;

    private int $galleryEnrichmentCount = 0;

    private int $galleryEnrichmentLimit = 0;

    public function previewSource(SupplierSource $source, ?array $parsedRows = null, array $options = []): array
    {
        $profileKey = $this->getSupplierProfileKey($source);
        $profile = $this->effectiveProfileSettings($source, $profileKey);
        $dbMappings = SupplierFieldMapping::query()
            ->forSource($source->id)
            ->get()
            ->keyBy('source_field')
            ->map(fn (SupplierFieldMapping $mapping) => [
                'standard_field_key' => $mapping->target_field,
                'mapping_status' => $mapping->mapping_status,
                'legacy_field_name' => $mapping->legacy_field_name,
                'confidence_score' => $mapping->confidence_score,
            ])
            ->all();

        $sourceFields = $this->fieldDictionary->getSourceFields($profileKey);
        if ($sourceFields === [] && !empty($parsedRows)) {
            $sourceFields = $this->collectPreviewSourceFields($parsedRows);
        }
        $suggestedMappings = $this->fieldDictionary->suggestMappings($sourceFields, $profileKey);
        $mappingSource = !empty($dbMappings) ? 'db' : 'suggestion';
        $effectiveMappings = !empty($dbMappings)
            ? array_replace($suggestedMappings, $dbMappings)
            : $suggestedMappings;
        $mappingWarnings = $this->fieldDictionary->validateRequiredMappings($effectiveMappings);
        $payloadRows = !empty($parsedRows) ? $parsedRows : $this->getDemoPayloadForProfile($profileKey);
        $sourceMode = !empty($parsedRows) ? 'live_source' : 'demo_fallback';
        $allowGalleryEnrichment = $options['allow_gallery_enrichment'] ?? true;
        $this->allowGalleryEnrichment = $sourceMode === 'live_source' && $allowGalleryEnrichment === true;
        $this->galleryEnrichmentCount = 0;
        $this->galleryEnrichmentLimit = max(1, min(50, (int) ($source->config['max_gallery_enrichment_products'] ?? 5)));

        $normalized = $this->normalizeRows(
            $source,
            $payloadRows,
            $effectiveMappings
        );

        $warningCount = collect($normalized['products'])->sum(fn (array $row) => count($row['warnings'] ?? []))
            + collect($normalized['variants'])->sum(fn (array $row) => count($row['warnings'] ?? []));
        $errorCount = collect($normalized['products'])->sum(fn (array $row) => count($row['errors'] ?? []))
            + collect($normalized['variants'])->sum(fn (array $row) => count($row['errors'] ?? []));

        return [
            'products' => array_values($normalized['products']),
            'variants' => array_values($normalized['variants']),
            'stats' => [
                'product_count' => count($normalized['products']),
                'variant_count' => count($normalized['variants']),
                'warning_count' => $warningCount + count($mappingWarnings),
                'error_count' => $errorCount,
                'matched_fields' => collect($effectiveMappings)->filter(fn (array $mapping) => filled($mapping['standard_field_key'] ?? null))->count(),
                'mapping_source' => $mappingSource,
                'records_read' => count($payloadRows),
            ],
            'mapping_source' => $mappingSource,
            'mapping_warnings' => $mappingWarnings,
            'source_mode' => $sourceMode,
            'profile_key' => $profileKey,
            'profile_notes' => [
                'supplier_prefix' => $profile['supplier_code_prefix'] ?? '-',
                'generated_code_template' => $profile['generated_code_template'] ?? '-',
                'generated_variant_code_template' => $profile['generated_variant_code_template'] ?? '-',
                'generated_name_template' => $profile['generated_name_template'] ?? '-',
                'product_model' => $profile['product_model'] ?? '-',
                'product_node_path' => $source->config['product_node_path'] ?? ($profile['product_node_path'] ?? '-'),
            ],
        ];
    }

    public function getSupplierProfileKey(SupplierSource $source): string
    {
        return $this->fieldDictionary->resolveProfileTemplateKey(
            (array) ($source->config ?? []),
            $source->supplier?->code,
            $source->supplier?->name
        ) ?? 'ETKIN';
    }

    public function getDemoPayloadForProfile(string $profileKey): array
    {
        return match ($profileKey) {
            'ETKIN' => [[
                'urun_id' => 17899,
                'kategori_id' => 100,
                'kategori_adi' => 'USB Bellekler',
                'urun_kodu' => '8115-S-16GB',
                'urun_kodgrup' => '8115',
                'urun_isim' => 'Metal USB Bellek',
                'urun_baslik' => '8115-S-16GB Metal USB Bellek',
                'urun_aciklama' => 'Metal kasalı USB bellek.',
                'urun_renk' => 'Siyah',
                'urun_ebat' => '16 GB',
                'toplam_stok' => 2000,
                'urun_fiyat' => '0.000',
                'urun_fiyat_virgul' => '0,000',
                'fiyat_kdv' => 20,
                'kirmiziurun' => 0,
                'urun_trase' => 'https://example.com/trase/8115',
                'katalog_sayfa_no' => 44,
                'resim1' => 'etkin-main.jpg',
                'resim2' => 'etkin-gallery-2.jpg',
                'md5' => 'hash-8115',
                'varyantlar' => [
                    [
                        'urun_id' => 12406,
                        'urun_kodu' => '8115-32GB',
                        'urun_kodgrup' => '8115',
                        'urun_isim' => 'Metal USB Bellek',
                        'urun_baslik' => '8115-32GB Metal USB Bellek',
                        'urun_renk' => '',
                        'urun_ebat' => '32 GB',
                        'toplam_stok' => 2468,
                        'urun_fiyat' => '0.000',
                        'fiyat_kdv' => '20',
                        'kirmiziurun' => 0,
                        'resim1' => 'etkin-variant.jpg',
                        'resim2' => 'etkin-variant-2.jpg',
                        'md5' => 'hash-8115-32',
                    ],
                ],
            ]],
            'AKDENIZ' => [[
                'urun_id' => '9001',
                'urunkodu' => '509-BK Siyah',
                'urunattr_id' => 'A1',
                'urunattrgr' => '509-BK',
                'urunattradi' => 'Siyah',
                'urunadi' => 'Kurumsal Mug',
                'pure_prodname' => 'Kurumsal Mug',
                'listefiyati' => '110,00',
                'iskonto' => '5',
                'netfiyat' => '85,00',
                'kur' => 'TL',
                'kdvorani' => '20',
                'stokmiktar' => '320',
                'stokresim' => '',
                'urunresim' => 'urunresim.jpg',
                'urunresim1' => 'urunresim1.jpg',
                'kategori' => 'Kurumsal Setler',
                'discat_name' => 'Kurumsal Setler',
                'urunaciklamasi' => 'Kurumsal kullanım için seramik mug.',
                'urunbaskifiyatlari' => '<p>Tek renk baskı dahil değildir.</p>',
                'stoktag' => 'mug,seramik',
            ]],
            'ILPEN' => [[
                'UrunKartiID' => '1174',
                'UrunAdi' => 'İlpen Matara',
                'UrunGrupKodu' => '1174',
                'ResimUrl' => 'ilpen-parent.jpg',
                'KategoriMain' => 'Termos / Matara',
                'KategoriSub' => 'Promosyon',
                'AlisFiyati' => '95',
                'KdvOrani' => '20',
                'ParaBirimi' => 'TL',
                'TumVaryantToplamStokAdedi' => '150',
                'Varyasyonlar' => [
                    [
                        'VaryasyonID' => 'IL-01',
                        'StokKodu' => '1174 Siyah',
                        'StokAdedi' => '70',
                        'EkSecenekOzellik.Ozellik[Tanim=Renk]' => 'Siyah',
                        'VaryasyonResim' => '',
                    ],
                    [
                        'VaryasyonID' => 'IL-02',
                        'StokKodu' => '1174 Fıstık Yeşili',
                        'StokAdedi' => '80',
                        'EkSecenekOzellik.Ozellik[Tanim=Renk]' => 'Fıstık Yeşili',
                        'VaryasyonResim' => 'ilpen-variant.jpg',
                    ],
                ],
            ]],
            'YENI-NESIL' => [[
                'uid' => '406323',
                'kod' => '406323',
                'kodgrup' => '4063',
                'stokkod' => '4063 Turkuaz',
                'renk' => 'Turkuaz',
                'ebat' => '750 ML',
                'turuncu' => '1',
                'stok' => '240',
                'toplamstok' => '240',
                'resim1' => 'yn-image.jpg',
                'resim2' => 'yn-image-2.jpg',
                'resim3' => 'yn-image-3.jpg',
                'fiyat' => '38',
                'dolar_fiyat' => '1.40',
                'kdv' => '20',
                'kategori' => 'Termos Matara',
            ]],
            'POZITRON_JSON' => [[
                'id' => 1,
                'urun_sku' => 'PZ-100',
                'urun_adi' => 'Pozitron Matara',
                'urun_aciklamasi' => 'Paslanmaz çelik promosyon matara.',
                'urun_url' => 'https://pozitronpromosyon.com/urun/pozitron-matara',
                'kategoriler' => [
                    ['id' => 11, 'ad' => 'Matara', 'slug' => 'matara'],
                ],
                'urun_gorselleri' => [
                    'https://pozitronpromosyon.com/uploads/pz-parent.jpg',
                ],
                'urun_fiyati' => '12.50',
                'kdv_orani' => '20',
                'varyasyonlar' => [
                    [
                        'varyasyon_id' => 101,
                        'stok_kodu' => 'PZ-100-KRM',
                        'renk' => 'Kırmızı',
                        'stok_adedi' => 25,
                        'fiyat' => '12.50',
                        'gorseller' => [
                            'https://pozitronpromosyon.com/uploads/pz-kirmizi.jpg',
                        ],
                        'urun_url' => 'https://pozitronpromosyon.com/urun/pozitron-matara?attribute_pa_renk=kirmizi',
                    ],
                ],
            ]],
            default => [[
                'urun_id' => '',
                'urun_kodu' => '0506-L',
                'urun_grupkodu' => '0506',
                'urun_adi' => 'Plastik Kalem Lacivert',
                'urun_resim' => 'image.jpg',
                'urun_kategori' => 'Canta Grubu',
                'urun_stok' => '1250',
                'urun_fiyat' => '9.20',
                'urun_kirmizi' => '0',
            ]],
        };
    }

    public function normalizeRows(SupplierSource $source, array $payloadRows, array $mappings): array
    {
        $profileKey = $this->getSupplierProfileKey($source);
        $profile = $this->effectiveProfileSettings($source, $profileKey);
        $productModel = $profile['product_model'] ?? 'flat_single_row';

        $result = [
            'products' => [],
            'variants' => [],
        ];

        foreach ($payloadRows as $row) {
            $normalizedRows = match ($productModel) {
                'record_variant_row' => $this->normalizeRecordVariantRow($source, $row, $mappings, $profileKey),
                'parent_nested_variant' => $this->normalizeParentVariantRow($source, $row, $mappings, $profileKey),
                'parent_nested_variant_json' => $this->normalizeParentVariantRow($source, $row, $mappings, $profileKey),
                default => $this->normalizeFlatRow($source, $row, $mappings, $profileKey, $productModel === 'flat_group_variant'),
            };

            foreach ($normalizedRows['products'] as $product) {
                $result['products'][$product['import_hash']] = $product;
            }

            foreach ($normalizedRows['variants'] as $variant) {
                $result['variants'][$variant['import_hash']] = $variant;
            }
        }

        return $result;
    }

    public function normalizeFlatRow(SupplierSource $source, array $row, array $mappings, string $profileKey, bool $createVariant = false): array
    {
        $normalized = $this->mapRowToStandardFields($row, $mappings);
        $product = $this->buildProductPayload($source, $row, $normalized, $profileKey);

        $variants = [];

        if ($createVariant || filled($normalized['variant_stock_code'] ?? null) || filled($normalized['variant_color'] ?? null)) {
            $variant = $this->buildVariantPayload($source, $row, $normalized, $profileKey, $product);
            $variants[$variant['import_hash']] = $variant;

            if (!empty($product['supplier_group_code'])) {
                $product['warnings'][] = $this->warningMessage('group_code_as_variant');
                $product['warnings'] = array_values(array_unique(array_filter($product['warnings'])));
            }
        }

        return [
            'products' => [$product['import_hash'] => $product],
            'variants' => $variants,
        ];
    }

    public function normalizeRecordVariantRow(SupplierSource $source, array $row, array $mappings, string $profileKey): array
    {
        $normalized = $this->mapRowToStandardFields($row, $mappings);
        $product = $this->buildProductPayload($source, $row, $normalized, $profileKey);
        $variant = $this->buildVariantPayload($source, $row, $normalized, $profileKey, $product);

        $product['warnings'][] = $this->warningMessage('group_code_as_variant');
        $product['warnings'] = array_values(array_unique(array_filter($product['warnings'])));

        return [
            'products' => [$product['import_hash'] => $product],
            'variants' => [$variant['import_hash'] => $variant],
        ];
    }

    public function normalizeParentVariantRow(SupplierSource $source, array $row, array $mappings, string $profileKey): array
    {
        $parentRow = collect($row)->except(['Varyasyonlar', 'UrunSecenek', 'varyantlar', 'varyasyonlar'])->all();
        $normalizedParent = $this->mapRowToStandardFields($parentRow, $mappings);
        $product = $this->buildProductPayload($source, $parentRow, $normalizedParent, $profileKey);
        $product['warnings'][] = $this->warningMessage('parent_card_found');
        $product['warnings'] = array_values(array_unique(array_filter($product['warnings'])));

        $variants = [];
        $variantRows = $row['Varyasyonlar'] ?? null;
        if (!is_array($variantRows)) {
            $variantRows = data_get($row, 'UrunSecenek.Secenek');
        }
        if (!is_array($variantRows)) {
            $variantRows = $row['varyantlar'] ?? null;
        }
        if (!is_array($variantRows)) {
            $variantRows = $row['varyasyonlar'] ?? [];
        }
        if (is_array($variantRows) && !array_is_list($variantRows)) {
            $variantRows = [$variantRows];
        }

        if ($profileKey === 'ETKIN' && (bool) ($this->fieldDictionary->getSupplierProfile($profileKey)['include_parent_as_variant'] ?? false)) {
            $parentVariant = $this->buildVariantPayload($source, $parentRow, $normalizedParent, $profileKey, $product);
            $variants[$parentVariant['import_hash']] = $parentVariant;
        }

        foreach (($variantRows ?? []) as $variantRow) {
            $combinedRaw = array_merge($parentRow, $variantRow);
            $normalizedVariant = $this->mapRowToStandardFields($combinedRaw, $mappings);
            $variant = $this->buildVariantPayload($source, $combinedRaw, $normalizedVariant, $profileKey, $product);
            $variants[$variant['import_hash']] = $variant;
        }

        if ($profileKey === 'POZITRON_JSON' && $variants === []) {
            $flatVariant = $this->buildVariantPayload($source, $parentRow, $normalizedParent, $profileKey, $product);
            $flatVariant['warnings'][] = 'Bu üründe varyasyon listesi boş geldiği için flat/satılabilir ürün olarak değerlendirildi.';
            $flatVariant['warnings'] = array_values(array_unique(array_filter($flatVariant['warnings'])));
            $variants[$flatVariant['import_hash']] = $flatVariant;
        }

        $variantStockTotal = collect($variants)
            ->map(fn (array $variant) => $variant['variant_stock_quantity'] ?? null)
            ->filter(fn ($stock) => $stock !== null && $stock !== '')
            ->sum();

        if ($variantStockTotal > 0 || ($variantStockTotal === 0.0 && !empty($variants))) {
            if (!array_key_exists('stock_quantity', $product) || $product['stock_quantity'] === null) {
                $product['stock_quantity'] = $variantStockTotal;
            }

            if (!array_key_exists('total_variant_stock_quantity', $product) || $product['total_variant_stock_quantity'] === null) {
                $product['total_variant_stock_quantity'] = $variantStockTotal;
            }

            $product['normalized_payload']['stock_quantity'] = $product['stock_quantity'];
            $product['normalized_payload']['total_variant_stock_quantity'] = $product['total_variant_stock_quantity'];
        }

        return [
            'products' => [$product['import_hash'] => $product],
            'variants' => $variants,
        ];
    }

    public function applyImageFallback(array $variant, array $product): array
    {
        if (blank($variant['variant_image_url'] ?? null) && filled($product['parent_image_url'] ?? $product['image_url'] ?? null)) {
            $variant['variant_image_url'] = $product['parent_image_url'] ?? $product['image_url'];
            $variant['image_fallback_used'] = true;
            $variant['warnings'][] = $this->warningMessage('variant_image_fallback');
        }

        $variant['warnings'] = array_values(array_unique(array_filter($variant['warnings'] ?? [])));

        return $variant;
    }

    public function buildImportHash(SupplierSource $source, array $parts): string
    {
        $parts = array_filter(array_map(fn ($value) => trim((string) ($value ?? '')), $parts), fn ($value) => $value !== '');

        return sha1(implode('|', array_merge([$source->supplier_id, $source->id], $parts)));
    }

    private function mapRowToStandardFields(array $row, array $mappings): array
    {
        $normalized = [];

        foreach ($mappings as $sourceField => $mapping) {
            $standardField = $mapping['standard_field_key'] ?? null;

            if (blank($standardField)) {
                continue;
            }

            $value = data_get($row, $sourceField);
            if (is_array($value)) {
                continue;
            }

            $normalized[$standardField] = $this->castMappedValue($standardField, $value);
        }

        return $normalized;
    }

    private function collectPreviewSourceFields(array $rows): array
    {
        $fields = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($this->flattenSourceFieldPaths($row) as $field) {
                $fields[$field] = $field;
            }
        }

        return array_values($fields);
    }

    private function flattenSourceFieldPaths(array $payload, string $prefix = ''): array
    {
        $fields = [];

        foreach ($payload as $key => $value) {
            if (is_int($key)) {
                continue;
            }

            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value) && !array_is_list($value)) {
                $fields = array_merge($fields, $this->flattenSourceFieldPaths($value, $path));
                continue;
            }

            $fields[] = $path;
        }

        return $fields;
    }

    private function buildProductPayload(SupplierSource $source, array $rawRow, array $normalized, string $profileKey): array
    {
        $profile = $this->effectiveProfileSettings($source, $profileKey);
        $warnings = [];
        $errors = [];
        $normalized = $this->applyProfileSpecificNormalization($rawRow, $normalized, $profileKey, $warnings);
        $normalized = $this->applySupplierWarningNormalization($rawRow, $normalized, $warnings);
        $displayNormalized = $this->applyDisplayAttributeNormalization($normalized);
        $galleryData = $this->extractGalleryImages($source, $rawRow, $profileKey);
        $galleryImages = $galleryData['images'];
        $productImage = $this->resolveProductImage($source, $rawRow, $profileKey, $galleryImages);
        if ($profileKey === 'CUSTOM') {
            $productImage = $this->firstFilled([
                $this->normalizeSourceUrl($source, $displayNormalized['image_url'] ?? null),
                $this->normalizeSourceUrl($source, $displayNormalized['parent_image_url'] ?? null),
                $productImage,
            ]);
        }
        $imageSourceField = $this->resolveProductImageSourceField($rawRow, $profileKey, $galleryImages);
        if ($profileKey === 'CUSTOM' && blank($imageSourceField) && filled($displayNormalized['image_url'] ?? null)) {
            $imageSourceField = 'mapped:image_url';
        }

        $generatedProductCode = $this->productCodeNormalizer->generateProductCode([
            'PREFIX' => $profile['supplier_code_prefix'] ?? '',
            'SUPPLIER_PRODUCT_CODE' => $normalized['supplier_product_code'] ?? $normalized['variant_stock_code'] ?? '',
            'SUPPLIER_GROUP_CODE' => $normalized['supplier_group_code'] ?? '',
            'PARENT_CODE' => $normalized['parent_supplier_product_id'] ?? $normalized['supplier_group_code'] ?? '',
            'PARENT_SUPPLIER_PRODUCT_ID' => $normalized['parent_supplier_product_id'] ?? '',
            'VARIANT_COLOR' => $normalized['variant_color'] ?? '',
            'COLOR' => $normalized['variant_color'] ?? '',
            'PRODUCT_NAME' => $normalized['product_name'] ?? '',
        ], $profile['generated_code_template'] ?? '{SUPPLIER_PRODUCT_CODE}');

        if (($normalized['supplier_product_code'] ?? '') !== $this->productCodeNormalizer->normalizeCode((string) ($normalized['supplier_product_code'] ?? ''))) {
            $warnings[] = $this->warningMessage('code_normalized');
        }

        if (blank($generatedProductCode)) {
            $errors[] = $this->warningMessage('required_field_missing');
        }

        if (blank($normalized['supplier_product_id'] ?? null) && blank($normalized['parent_supplier_product_id'] ?? null) && filled($normalized['supplier_product_code'] ?? null)) {
            $warnings[] = $this->warningMessage('fallback_to_product_code');
        }

        if (blank($normalized['supplier_product_code'] ?? null) && filled($generatedProductCode)) {
            $warnings[] = $this->warningMessage('temporary_product_code_generated');
            if (str_starts_with((string) $generatedProductCode, 'ET-') && (($profile['supplier_code_prefix'] ?? null) === 'EL')) {
                $warnings[] = $this->warningMessage('wrong_supplier_prefix');
            }
        }

        if (($normalized['warning_flag'] ?? null) === true) {
            $warnings[] = $this->warningMessage('warning_flag_alias');
        }

        [$standardCategoryId, $categoryWarning] = $this->resolveStandardCategory($source, $normalized['supplier_category_name'] ?? null);
        if ($categoryWarning) {
            $warnings[] = $categoryWarning;
        }

        $productPayload = [
            'supplier_product_id' => $normalized['supplier_product_id'] ?? $normalized['parent_supplier_product_id'] ?? null,
            'supplier_product_code' => $normalized['supplier_product_code'] ?? $normalized['variant_stock_code'] ?? null,
            'supplier_group_code' => $normalized['supplier_group_code'] ?? null,
            'generated_product_code' => $generatedProductCode,
            'product_name' => $displayNormalized['product_name'] ?? $displayNormalized['base_product_name'] ?? null,
            'base_product_name' => $displayNormalized['base_product_name'] ?? null,
            'supplier_category_name' => $normalized['supplier_category_name'] ?? $normalized['supplier_subcategory_name'] ?? null,
            'supplier_category_slug' => $normalized['supplier_category_slug'] ?? null,
            'standard_category_id' => $standardCategoryId,
            'product_url' => $this->normalizeSourceUrl($source, $displayNormalized['product_url'] ?? $displayNormalized['supplier_page'] ?? null),
            'detail_url' => $this->normalizeSourceUrl($source, $displayNormalized['detail_url'] ?? null),
            'artwork_template_url' => $this->normalizeSourceUrl($source, $displayNormalized['artwork_template_url'] ?? null),
            'purchase_price' => $normalized['purchase_price'] ?? null,
            'net_price' => $normalized['net_price'] ?? null,
            'list_price' => $normalized['list_price'] ?? null,
            'closed_list_price' => $normalized['closed_list_price'] ?? null,
            'discount_rate' => $normalized['discount_rate'] ?? null,
            'alternative_price' => $normalized['alternative_price'] ?? null,
            'currency' => $normalized['currency'] ?? 'TL',
            'vat_rate' => $normalized['vat_rate'] ?? null,
            'usd_price' => $normalized['usd_price'] ?? null,
            'image_url' => $productImage,
            'parent_image_url' => $displayNormalized['parent_image_url'] ?? $productImage,
            'gallery_images' => $galleryImages,
            'feed_gallery_images' => $galleryImages,
            'page_gallery_images' => [],
            'gallery_origin' => 'feed',
            'image_source_field' => $imageSourceField,
            'gallery_source_fields' => $galleryData['source_fields'],
            'image_fallback_used' => false,
            'warning_flag' => (bool) ($normalized['warning_flag'] ?? false),
            'supplier_warning_flag' => (bool) ($normalized['supplier_warning_flag'] ?? false),
            'supplier_warning_type' => $normalized['supplier_warning_type'] ?? null,
            'net_price_warning' => (bool) ($normalized['net_price_warning'] ?? false),
            'price_policy_warning' => (bool) ($normalized['price_policy_warning'] ?? false),
            'pricing_policy_type' => $normalized['pricing_policy_type'] ?? null,
            'supplier_price_note' => $normalized['supplier_price_note'] ?? null,
            'temporary_product_code' => blank($normalized['supplier_product_code'] ?? null) && filled($generatedProductCode),
            'raw_payload' => $rawRow,
            'normalized_payload' => array_merge($displayNormalized, [
                'image_url' => $productImage,
                'parent_image_url' => $displayNormalized['parent_image_url'] ?? $productImage,
                'gallery_images' => $galleryImages,
                'feed_gallery_images' => $galleryImages,
                'page_gallery_images' => [],
                'gallery_origin' => 'feed',
                'image_source_field' => $imageSourceField,
                'gallery_source_fields' => $galleryData['source_fields'],
                'image_fallback_used' => false,
                'product_url' => $this->normalizeSourceUrl($source, $displayNormalized['product_url'] ?? $displayNormalized['supplier_page'] ?? null),
                'detail_url' => $this->normalizeSourceUrl($source, $displayNormalized['detail_url'] ?? null),
                'artwork_template_url' => $this->normalizeSourceUrl($source, $displayNormalized['artwork_template_url'] ?? null),
                'alternative_price' => $normalized['alternative_price'] ?? null,
                'usd_price' => $normalized['usd_price'] ?? null,
                'pricing_policy_type' => $normalized['pricing_policy_type'] ?? null,
                'supplier_price_note' => $normalized['supplier_price_note'] ?? null,
                'supplier_warning_flag' => (bool) ($normalized['supplier_warning_flag'] ?? false),
                'supplier_warning_type' => $normalized['supplier_warning_type'] ?? null,
                'supplier_category_slug' => $normalized['supplier_category_slug'] ?? null,
                'stock_source_type' => $normalized['stock_source_type'] ?? null,
                'bundle_component_count' => $normalized['bundle_component_count'] ?? 0,
            ]),
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'errors' => array_values(array_unique(array_filter($errors))),
            'import_hash' => $this->buildImportHash($source, [
                'product',
                $normalized['parent_supplier_product_id'] ?? '',
                $normalized['supplier_product_id'] ?? '',
                $normalized['supplier_group_code'] ?? '',
                $generatedProductCode,
            ]),
            'status' => empty($errors) ? (empty($warnings) ? 'OK' : 'Uyarı') : 'Hata',
            'product_model' => $profile['product_model'] ?? 'flat_single_row',
            'mapping_mode' => null,
            'mapping_badge' => null,
            'description' => $displayNormalized['description'] ?? null,
            'stock_quantity' => $normalized['stock_quantity'] ?? $normalized['total_variant_stock_quantity'] ?? $normalized['variant_stock_quantity'] ?? null,
            'variant_stock_quantity' => $normalized['variant_stock_quantity'] ?? null,
            'total_variant_stock_quantity' => $normalized['total_variant_stock_quantity'] ?? null,
            'color' => $displayNormalized['display_variant_color'] ?? $displayNormalized['variant_color'] ?? null,
            'size' => $displayNormalized['display_size'] ?? $displayNormalized['size'] ?? null,
            'extracted_color_source' => $normalized['extracted_color_source'] ?? null,
        ];

        $productPayload = $this->applyProductPayloadFallbacks($productPayload, $rawRow, $profileKey);

        if ($this->shouldEnrichGalleryFromProductPage($source, $productPayload)) {
            $this->galleryEnrichmentCount++;
            $productPayload = $this->productPageGalleryEnrichment->enrich($productPayload, $source);
            $productPayload['normalized_payload']['gallery_images'] = $productPayload['gallery_images'] ?? [];
            $productPayload['normalized_payload']['feed_gallery_images'] = $productPayload['feed_gallery_images'] ?? [];
            $productPayload['normalized_payload']['page_gallery_images'] = $productPayload['page_gallery_images'] ?? [];
            $productPayload['normalized_payload']['gallery_origin'] = $productPayload['gallery_origin'] ?? 'feed';
            $productPayload['normalized_payload']['product_url'] = $productPayload['product_url'] ?? null;
            $productPayload['normalized_payload']['detail_url'] = $productPayload['detail_url'] ?? null;
            $productPayload['warnings'] = array_values(array_unique(array_filter($productPayload['warnings'] ?? [])));
        } elseif ($this->allowGalleryEnrichment && (bool) ($source->config['enrich_gallery_from_product_page'] ?? false) && blank($productPayload['product_url'] ?? $productPayload['detail_url'] ?? null)) {
            $productPayload['warnings'][] = 'Ürün sayfası linki bulunmadığı için galeri zenginleştirme yapılmadı.';
            $productPayload['warnings'] = array_values(array_unique(array_filter($productPayload['warnings'])));
        } elseif ($this->allowGalleryEnrichment && (bool) ($source->config['enrich_gallery_from_product_page'] ?? false) && filled($productPayload['product_url'] ?? $productPayload['detail_url'] ?? null)) {
            $productPayload['warnings'][] = 'Galeri zenginleştirme limit nedeniyle uygulanmadı.';
            $productPayload['warnings'] = array_values(array_unique(array_filter($productPayload['warnings'])));
        }

        if (blank($productPayload['image_url'] ?? null)) {
            $productPayload['warnings'][] = $this->warningMessage('missing_product_image');
            $productPayload['warnings'] = array_values(array_unique(array_filter($productPayload['warnings'])));
        }

        return $productPayload;
    }

    private function buildVariantPayload(SupplierSource $source, array $rawRow, array $normalized, string $profileKey, array $product): array
    {
        $profile = $this->effectiveProfileSettings($source, $profileKey);
        $warnings = [];
        $errors = [];
        $normalized = $this->applyProfileSpecificNormalization($rawRow, $normalized, $profileKey, $warnings);
        $normalized = $this->applySupplierWarningNormalization($rawRow, $normalized, $warnings);
        $displayNormalized = $this->applyDisplayAttributeNormalization($normalized);
        $resolvedVariantImage = $this->resolveVariantImage(
            $source,
            $rawRow,
            $product['parent_image_url'] ?? $product['image_url'] ?? null,
            $profileKey,
            $product['gallery_images'] ?? []
        );
        if ($profileKey === 'CUSTOM' && blank($resolvedVariantImage['url'] ?? null) && filled($displayNormalized['variant_image_url'] ?? null)) {
            $resolvedVariantImage = [
                'url' => $this->normalizeSourceUrl($source, $displayNormalized['variant_image_url']),
                'fallback_used' => false,
                'warning' => null,
                'source_field' => 'mapped:variant_image_url',
            ];
        }
        $variantGallery = $this->buildVariantGalleryImages(
            $resolvedVariantImage['url'] ?? null,
            $product['gallery_images'] ?? []
        );
        if (!empty($resolvedVariantImage['warning'])) {
            $warnings[] = $resolvedVariantImage['warning'];
        }

        $generatedVariantCode = $this->productCodeNormalizer->generateVariantCode([
            'PREFIX' => $profile['supplier_code_prefix'] ?? '',
            'SUPPLIER_PRODUCT_CODE' => $normalized['supplier_product_code'] ?? $normalized['variant_stock_code'] ?? '',
            'SUPPLIER_GROUP_CODE' => $normalized['supplier_group_code'] ?? '',
            'PARENT_CODE' => $normalized['parent_supplier_product_id'] ?? $normalized['supplier_group_code'] ?? '',
            'PARENT_SUPPLIER_PRODUCT_ID' => $normalized['parent_supplier_product_id'] ?? '',
            'VARIANT_STOCK_CODE' => $normalized['variant_stock_code'] ?? '',
            'VARIANT_COLOR' => $normalized['variant_color'] ?? '',
            'COLOR' => $normalized['variant_color'] ?? '',
            'VARIANT_ID' => $normalized['variant_id'] ?? '',
            'PRODUCT_NAME' => $normalized['product_name'] ?? '',
        ], $profile['generated_variant_code_template'] ?? '{SUPPLIER_PRODUCT_CODE}');

        $variant = [
            'parent_supplier_product_id' => $normalized['parent_supplier_product_id'] ?? $normalized['supplier_product_id'] ?? null,
            'supplier_group_code' => $normalized['supplier_group_code'] ?? null,
            'variant_id' => $normalized['variant_id'] ?? null,
            'variant_code' => $normalized['variant_stock_code'] ?? $normalized['supplier_product_code'] ?? null,
            'variant_stock_code' => $normalized['variant_stock_code'] ?? $normalized['supplier_product_code'] ?? null,
            'generated_variant_code' => $generatedVariantCode,
            'variant_name' => $displayNormalized['variant_name'] ?? $displayNormalized['display_variant_color'] ?? $displayNormalized['variant_color'] ?? null,
            'variant_color' => $displayNormalized['display_variant_color'] ?? $displayNormalized['variant_color'] ?? null,
            'variant_stock_quantity' => $normalized['variant_stock_quantity'] ?? $normalized['stock_quantity'] ?? null,
            'purchase_price' => $normalized['purchase_price'] ?? $product['purchase_price'] ?? null,
            'net_price' => $normalized['net_price'] ?? $product['net_price'] ?? null,
            'list_price' => $normalized['list_price'] ?? $product['list_price'] ?? null,
            'closed_list_price' => $normalized['closed_list_price'] ?? $product['closed_list_price'] ?? null,
            'discount_rate' => $normalized['discount_rate'] ?? $product['discount_rate'] ?? null,
            'alternative_price' => $normalized['alternative_price'] ?? $product['alternative_price'] ?? null,
            'usd_price' => $normalized['usd_price'] ?? $product['usd_price'] ?? null,
            'currency' => $normalized['currency'] ?? $product['currency'] ?? null,
            'net_price_warning' => (bool) ($normalized['net_price_warning'] ?? $product['net_price_warning'] ?? false),
            'price_policy_warning' => (bool) ($normalized['price_policy_warning'] ?? $product['price_policy_warning'] ?? false),
            'pricing_policy_type' => $normalized['pricing_policy_type'] ?? $product['pricing_policy_type'] ?? null,
            'artwork_template_url' => $this->normalizeSourceUrl($source, $displayNormalized['artwork_template_url'] ?? $product['artwork_template_url'] ?? null),
            'variant_product_url' => $this->normalizeSourceUrl($source, $displayNormalized['variant_product_url'] ?? $displayNormalized['product_url'] ?? $product['product_url'] ?? null),
            'warning_flag' => (bool) ($normalized['warning_flag'] ?? false),
            'supplier_warning_flag' => (bool) ($normalized['supplier_warning_flag'] ?? $product['supplier_warning_flag'] ?? false),
            'supplier_warning_type' => $normalized['supplier_warning_type'] ?? $product['supplier_warning_type'] ?? null,
            'variant_image_url' => $resolvedVariantImage['url'] ?? null,
            'parent_image_url' => $product['parent_image_url'] ?? $displayNormalized['parent_image_url'] ?? $displayNormalized['image_url'] ?? null,
            'image_fallback_used' => (bool) ($resolvedVariantImage['fallback_used'] ?? false),
            'variant_image_source_field' => $resolvedVariantImage['source_field'] ?? null,
            'gallery_images' => $variantGallery,
            'variant_gallery_images' => $variantGallery,
            'gallery_origin' => $product['gallery_origin'] ?? 'feed',
            'temporary_variant_code' => blank($normalized['variant_stock_code'] ?? null) && filled($generatedVariantCode),
            'warnings' => $warnings,
            'errors' => $errors,
            'raw_payload' => $rawRow,
            'normalized_payload' => array_merge($displayNormalized, [
                'variant_image_url' => $resolvedVariantImage['url'] ?? null,
                'parent_image_url' => $product['parent_image_url'] ?? $displayNormalized['parent_image_url'] ?? $displayNormalized['image_url'] ?? null,
                'image_fallback_used' => (bool) ($resolvedVariantImage['fallback_used'] ?? false),
                'variant_image_source_field' => $resolvedVariantImage['source_field'] ?? null,
                'gallery_images' => $variantGallery,
                'variant_gallery_images' => $variantGallery,
                'gallery_origin' => $product['gallery_origin'] ?? 'feed',
                'purchase_price' => $normalized['purchase_price'] ?? $product['purchase_price'] ?? null,
                'net_price' => $normalized['net_price'] ?? $product['net_price'] ?? null,
                'list_price' => $normalized['list_price'] ?? $product['list_price'] ?? null,
                'closed_list_price' => $normalized['closed_list_price'] ?? $product['closed_list_price'] ?? null,
                'discount_rate' => $normalized['discount_rate'] ?? $product['discount_rate'] ?? null,
                'alternative_price' => $normalized['alternative_price'] ?? $product['alternative_price'] ?? null,
                'usd_price' => $normalized['usd_price'] ?? $product['usd_price'] ?? null,
                'currency' => $normalized['currency'] ?? $product['currency'] ?? null,
                'net_price_warning' => (bool) ($normalized['net_price_warning'] ?? $product['net_price_warning'] ?? false),
                'price_policy_warning' => (bool) ($normalized['price_policy_warning'] ?? $product['price_policy_warning'] ?? false),
                'pricing_policy_type' => $normalized['pricing_policy_type'] ?? $product['pricing_policy_type'] ?? null,
                'supplier_price_note' => $normalized['supplier_price_note'] ?? $product['supplier_price_note'] ?? null,
                'supplier_warning_flag' => (bool) ($normalized['supplier_warning_flag'] ?? $product['supplier_warning_flag'] ?? false),
                'supplier_warning_type' => $normalized['supplier_warning_type'] ?? $product['supplier_warning_type'] ?? null,
                'artwork_template_url' => $this->normalizeSourceUrl($source, $displayNormalized['artwork_template_url'] ?? $product['artwork_template_url'] ?? null),
                'variant_product_url' => $this->normalizeSourceUrl($source, $displayNormalized['variant_product_url'] ?? $displayNormalized['product_url'] ?? $product['product_url'] ?? null),
                'extracted_color_source' => $normalized['extracted_color_source'] ?? null,
            ]),
            'variant_size' => $displayNormalized['display_size'] ?? $displayNormalized['size'] ?? null,
            'variant_attributes' => array_filter([
                'size' => $displayNormalized['display_size'] ?? $displayNormalized['size'] ?? null,
                'measure' => data_get($displayNormalized, 'variant_attributes.measure'),
                'capacity' => data_get($displayNormalized, 'variant_attributes.capacity'),
                'material' => data_get($displayNormalized, 'variant_attributes.material'),
                'option' => data_get($displayNormalized, 'variant_attributes.option'),
                'warning_flag' => $normalized['warning_flag'] ?? null,
                'supplier_warning_flag' => $normalized['supplier_warning_flag'] ?? $product['supplier_warning_flag'] ?? null,
                'supplier_warning_type' => $normalized['supplier_warning_type'] ?? $product['supplier_warning_type'] ?? null,
                'price_policy_warning' => $normalized['price_policy_warning'] ?? $product['price_policy_warning'] ?? null,
                'net_price_warning' => $normalized['net_price_warning'] ?? $product['net_price_warning'] ?? null,
                'pricing_policy_type' => $normalized['pricing_policy_type'] ?? $product['pricing_policy_type'] ?? null,
            ], fn ($value) => !is_null($value) && $value !== ''),
            'extracted_color_source' => $normalized['extracted_color_source'] ?? null,
        ];

        $variant = $this->applyVariantPayloadFallbacks($variant, $rawRow, $product, $profileKey);

        if (($normalized['warning_flag'] ?? null) === true) {
            $variant['warnings'][] = $this->warningMessage('warning_flag_alias');
        }

        if (blank($generatedVariantCode)) {
            $variant['errors'][] = $this->warningMessage('required_field_missing');
        }

        if (blank($normalized['variant_stock_code'] ?? null) && filled($generatedVariantCode)) {
            $variant['warnings'][] = $this->warningMessage('temporary_variant_code_generated');
        }

        if (blank($variant['variant_image_url'] ?? null)) {
            $variant = $this->applyImageFallback($variant, $product);
        }
        $variant['import_hash'] = $this->buildImportHash($source, [
            'variant',
            $variant['parent_supplier_product_id'] ?? '',
            $variant['variant_id'] ?? '',
            $variant['variant_stock_code'] ?? '',
            $variant['generated_variant_code'] ?? '',
        ]);
        $variant['status'] = empty($variant['errors']) ? (empty($variant['warnings']) ? 'OK' : 'Uyarı') : 'Hata';

        return $variant;
    }

    private function extractGalleryImages(SupplierSource $source, array $row, string $profileKey): array
    {
        $sourceFields = match ($profileKey) {
            'ETKIN' => array_map(fn (int $index) => 'resim' . $index, range(1, 9)),
            'AKDENIZ' => array_merge(['stokresim', 'urunresim'], array_map(fn (int $index) => 'urunresim' . $index, range(1, 13))),
            'ILPEN' => ['ResimUrl'],
            'YENI-NESIL' => array_map(fn (int $index) => 'resim' . $index, range(1, 9)),
            'POZITRON_JSON' => ['urun_gorselleri'],
            default => ['urun_resim', 'resim', 'image', 'image_url'],
        };

        $images = [];
        $usedSourceFields = [];

        foreach ($sourceFields as $field) {
            $value = $row[$field] ?? null;

            foreach ((is_array($value) ? $value : [$value]) as $candidate) {
                if (!filled($candidate)) {
                    continue;
                }

                $candidate = $this->normalizeSourceUrl($source, $candidate);
                if (!filled($candidate) || in_array($candidate, $images, true)) {
                    continue;
                }

                $images[] = $candidate;
                $usedSourceFields[] = $field;
            }
        }

        return [
            'images' => $images,
            'source_fields' => $usedSourceFields,
        ];
    }

    private function resolveProductImage(SupplierSource $source, array $row, string $profileKey, array $galleryImages): ?string
    {
        return $this->normalizeSourceUrl($source, match ($profileKey) {
            'ETKIN' => $this->firstFilled([
                $row['resim1'] ?? null,
                $row['urun_resim'] ?? null,
                $row['resim'] ?? null,
                $row['image'] ?? null,
                $row['image_url'] ?? null,
                $galleryImages[0] ?? null,
            ]),
            'AKDENIZ' => $this->firstFilled([
                $row['stokresim'] ?? null,
                $row['urunresim'] ?? null,
                $row['urunresim1'] ?? null,
                $galleryImages[0] ?? null,
            ]),
            'ILPEN' => $this->firstFilled([
                $row['ResimUrl'] ?? null,
                $galleryImages[0] ?? null,
            ]),
            'POZITRON_JSON' => $this->firstFilled([
                data_get($row, 'urun_gorselleri.0'),
                $galleryImages[0] ?? null,
            ]),
            'YENI-NESIL' => $this->firstFilled([
                $row['resim1'] ?? null,
                $galleryImages[0] ?? null,
            ]),
            default => $this->firstFilled([
                $row['urun_resim'] ?? null,
                $row['image_url'] ?? null,
                $galleryImages[0] ?? null,
            ]),
        });
    }

    private function resolveProductImageSourceField(array $row, string $profileKey, array $galleryImages): ?string
    {
        return match ($profileKey) {
            'ETKIN' => $this->firstFilledField($row, ['resim1'])
                ?? $this->firstFilledField($row, ['urun_resim', 'resim', 'image', 'image_url'])
                ?? (!empty($galleryImages) ? 'gallery_images' : null),
            'AKDENIZ' => $this->firstFilledField($row, ['stokresim', 'urunresim', 'urunresim1'])
                ?? (!empty($galleryImages) ? 'gallery_images' : null),
            'ILPEN' => $this->firstFilledField($row, ['ResimUrl'])
                ?? (!empty($galleryImages) ? 'gallery_images' : null),
            'POZITRON_JSON' => filled(data_get($row, 'urun_gorselleri.0'))
                ? 'urun_gorselleri.0'
                : (!empty($galleryImages) ? 'gallery_images' : null),
            'YENI-NESIL' => $this->firstFilledField($row, ['resim1'])
                ?? (!empty($galleryImages) ? 'gallery_images' : null),
            default => $this->firstFilledField($row, ['urun_resim', 'image_url'])
                ?? (!empty($galleryImages) ? 'gallery_images' : null),
        };
    }

    private function resolveVariantImage(SupplierSource $source, array $row, ?string $parentImage, string $profileKey, array $galleryImages = []): array
    {
        $variantFieldCandidates = match ($profileKey) {
            'ETKIN' => ['resim1', 'urun_resim', 'resim', 'image', 'image_url'],
            'AKDENIZ' => ['stokresim', 'urunresim', 'urunresim1'],
            'ILPEN' => ['VaryasyonResim', 'ResimUrl'],
            'POZITRON_JSON' => ['gorseller.0'],
            'YENI-NESIL' => ['resim1'],
            default => ['urun_resim', 'resim', 'image', 'image_url'],
        };
        $variantImage = $this->normalizeSourceUrl($source, $this->firstFilled(array_map(fn (string $field) => data_get($row, $field), $variantFieldCandidates)));
        $variantSourceField = $this->firstFilledField($row, $variantFieldCandidates);

        if (filled($variantImage)) {
            $warning = null;
            if ($profileKey === 'ILPEN' && $variantSourceField === 'ResimUrl') {
                $warning = 'Varyasyon görseli gelmedi, ana ürün görseli kullanıldı.';
            }

            return [
                'url' => $variantImage,
                'fallback_used' => $profileKey === 'ILPEN' && $variantSourceField === 'ResimUrl',
                'warning' => $warning,
                'source_field' => $variantSourceField,
            ];
        }

        $fallbackImage = $this->normalizeSourceUrl($source, $this->firstFilled([
            $parentImage,
            $galleryImages[0] ?? null,
        ]));

        return [
            'url' => $fallbackImage,
            'fallback_used' => filled($fallbackImage),
            'warning' => filled($fallbackImage) ? $this->warningMessage('variant_image_fallback') : null,
            'source_field' => filled($fallbackImage) ? 'parent_image_url fallback' : null,
        ];
    }

    private function castMappedValue(string $field, mixed $value): mixed
    {
        if (is_null($value)) {
            return null;
        }

        $value = is_string($value) ? trim($value) : $value;

        return match ($field) {
            'purchase_price', 'net_price', 'list_price', 'closed_list_price', 'discount_rate', 'alternative_price', 'usd_price', 'eur_price', 'vat_rate', 'stock_quantity', 'variant_stock_quantity', 'total_variant_stock_quantity'
                => $this->toDecimal($value),
            'warning_flag', 'supplier_warning_flag' => $this->toBooleanFlag($value),
            'price_policy_warning', 'net_price_warning', 'production_flag', 'image_fallback_used' => $this->toBooleanFlag($value),
            default => $value,
        };
    }

    private function toDecimal(mixed $value): ?float
    {
        if ($value === '' || is_null($value)) {
            return null;
        }

        $normalized = preg_replace('/[^\d,.\-]/', '', (string) $value) ?? '';

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function toBooleanFlag(mixed $value): bool
    {
        return !in_array(mb_strtolower(trim((string) $value), 'UTF-8'), ['', '0', 'false', 'hayir', 'yok', 'no'], true);
    }

    private function resolveStandardCategory(SupplierSource $source, ?string $supplierCategoryName): array
    {
        if (blank($supplierCategoryName)) {
            return [null, 'Hedef kategori eşleşmesi yok.'];
        }

        $mapping = SupplierCategoryMapping::query()
            ->where('supplier_id', $source->supplier_id)
            ->where(function ($query) use ($source) {
                $query->whereNull('supplier_source_id')
                    ->orWhere('supplier_source_id', $source->id);
            })
            ->where('source_category', $supplierCategoryName)
            ->whereNotNull('standard_category_id')
            ->first();

        if (!$mapping) {
            return [null, 'Hedef kategori eşleşmesi yok.'];
        }

        return [$mapping->standard_category_id, null];
    }

    private function applyProfileSpecificNormalization(array $rawRow, array $normalized, string $profileKey, array &$warnings): array
    {
        return match ($profileKey) {
            'ETKIN' => $this->applyEtkinNormalization($rawRow, $normalized, $warnings),
            'YENI-NESIL' => $this->applyYeniNesilNormalization($rawRow, $normalized, $warnings),
            'AKDENIZ' => $this->applyAkdenizColorExtraction(
                $rawRow,
                $this->applyAkdenizPricing($rawRow, $normalized, $warnings),
                $warnings
            ),
            'ILPEN' => $this->applyIlpenNormalization($rawRow, $normalized, $warnings),
            'POZITRON_JSON' => $this->applyPozitronNormalization($rawRow, $normalized, $warnings),
            default => $normalized,
        };
    }

    private function applyPozitronNormalization(array $rawRow, array $normalized, array &$warnings): array
    {
        $isVariantRow = filled($rawRow['varyasyon_id'] ?? null)
            || filled($rawRow['stok_kodu'] ?? null)
            || filled(data_get($rawRow, 'nitelikler.pa_renk'));
        $parentListPrice = $this->resolvePozitronListPrice($rawRow, [
            'urun_fiyati',
            'fiyat',
            'fiyat_normal',
        ], $warnings, 'parent');
        $variantListPrice = $this->resolvePozitronListPrice($rawRow, [
            'fiyat',
            'fiyat_normal',
            'urun_fiyati',
        ], $warnings, 'variant');
        $variantStock = $this->toDecimal($rawRow['stok_adedi'] ?? null);

        $normalized['supplier_product_id'] = $normalized['supplier_product_id'] ?? ($rawRow['id'] ?? null);
        $normalized['parent_supplier_product_id'] = $normalized['parent_supplier_product_id'] ?? ($rawRow['id'] ?? null);
        $normalized['supplier_product_code'] = $this->firstFilled([
            $rawRow['urun_sku'] ?? null,
            $normalized['supplier_product_code'] ?? null,
        ]);
        $normalized['supplier_group_code'] = $this->firstFilled([
            $normalized['supplier_group_code'] ?? null,
            $rawRow['urun_sku'] ?? null,
            $rawRow['id'] ?? null,
        ]);
        $normalized['product_name'] = $this->firstFilled([
            $rawRow['urun_adi'] ?? null,
            $normalized['product_name'] ?? null,
            $normalized['supplier_product_code'] ?? null,
        ]);
        $normalized['description'] = $normalized['description'] ?? ($rawRow['urun_aciklamasi'] ?? null);
        $normalized['supplier_category_id'] = $normalized['supplier_category_id'] ?? data_get($rawRow, 'kategoriler.0.id');
        $normalized['supplier_category_name'] = $this->firstFilled([
            $normalized['supplier_category_name'] ?? null,
            data_get($rawRow, 'kategoriler.0.ad'),
        ]);
        $normalized['supplier_category_slug'] = $normalized['supplier_category_slug'] ?? data_get($rawRow, 'kategoriler.0.slug');
        $normalized['product_url'] = $normalized['product_url'] ?? ($rawRow['urun_url'] ?? null);
        $normalized['variant_product_url'] = $normalized['variant_product_url'] ?? ($isVariantRow ? ($rawRow['urun_url'] ?? null) : null);
        $normalized['list_price'] = $isVariantRow
            ? ($variantListPrice ?? $parentListPrice)
            : ($parentListPrice ?? $variantListPrice);
        $normalized['purchase_price'] = null;
        $normalized['net_price'] = null;
        $normalized['net_price_warning'] = false;
        $normalized['price_policy_warning'] = false;
        $normalized['pricing_policy_type'] = 'list_price';
        $normalized['currency'] = 'USD';
        $normalized['vat_rate'] = $this->toDecimal($rawRow['kdv_orani'] ?? $normalized['vat_rate'] ?? null);
        $normalized['stock_quantity'] = $isVariantRow ? null : ($normalized['stock_quantity'] ?? null);
        $normalized['variant_stock_quantity'] = $isVariantRow
            ? ($variantStock ?? $normalized['variant_stock_quantity'] ?? null)
            : ($normalized['variant_stock_quantity'] ?? null);
        $normalized['variant_id'] = $normalized['variant_id'] ?? ($rawRow['varyasyon_id'] ?? null);
        $normalized['variant_stock_code'] = $this->firstFilled([
            $rawRow['stok_kodu'] ?? null,
            $normalized['variant_stock_code'] ?? null,
            filled($rawRow['varyasyon_id'] ?? null) && filled($normalized['supplier_product_code'] ?? null)
                ? ($normalized['supplier_product_code'] . '-' . $rawRow['varyasyon_id'])
                : null,
            !$isVariantRow ? ($normalized['supplier_product_code'] ?? null) : null,
        ]);
        $normalized['variant_color'] = $this->firstFilled([
            $rawRow['renk'] ?? null,
            data_get($rawRow, 'nitelikler.pa_renk'),
            $normalized['variant_color'] ?? null,
        ]);
        $normalized['extracted_color_source'] = $normalized['extracted_color_source'] ?? $this->firstFilled([
            filled($rawRow['renk'] ?? null) ? 'renk' : null,
            filled(data_get($rawRow, 'nitelikler.pa_renk')) ? 'nitelikler.pa_renk' : null,
        ]);
        $normalized['gallery_images'] = collect((array) ($rawRow['urun_gorselleri'] ?? []))
            ->filter(fn ($value) => filled($value))
            ->values()
            ->all();
        $normalized['variant_gallery_images'] = collect((array) ($rawRow['gorseller'] ?? []))
            ->filter(fn ($value) => filled($value))
            ->values()
            ->all();
        $normalized['image_url'] = $normalized['image_url'] ?? ($normalized['gallery_images'][0] ?? null);
        $normalized['variant_image_url'] = $normalized['variant_image_url'] ?? ($normalized['variant_gallery_images'][0] ?? null);
        $normalized['supplier_price_note'] = 'Pozitron liste fiyatı USD olarak gelir.';
        $normalized['bundle_component_count'] = is_array($rawRow['bilesenler'] ?? null) ? count($rawRow['bilesenler']) : 0;
        $normalized['stock_source_type'] = data_get($rawRow, 'stok_kaynagi.tip');
        if (
            filled($rawRow['fiyat'] ?? null)
            && filled($rawRow['fiyat_normal'] ?? null)
            && !$this->pricesAreEqual($this->toDecimal($rawRow['fiyat']), $this->toDecimal($rawRow['fiyat_normal']))
        ) {
            $normalized['price_policy_warning'] = true;
        }

        if (!$isVariantRow && blank($normalized['supplier_category_name'] ?? null)) {
            $warnings[] = 'Kategori bekliyor.';
        }
        if ($isVariantRow && blank($normalized['variant_stock_code'] ?? null)) {
            $warnings[] = $this->warningMessage('temporary_variant_code_generated');
        }
        if (blank($normalized['list_price'])) {
            $warnings[] = 'Liste fiyatı eksik.';
        }

        return $normalized;
    }

    private function applyDisplayAttributeNormalization(array $normalized): array
    {
        $display = $normalized;
        $originalAttributes = is_array($normalized['variant_attributes'] ?? null) ? $normalized['variant_attributes'] : [];

        $display['original_variant_color'] = $normalized['variant_color'] ?? null;
        $display['original_variant_size'] = $normalized['variant_size'] ?? $normalized['size'] ?? null;
        $display['original_variant_attributes'] = $originalAttributes;

        $displayColor = $this->attributeValueNormalizer->normalize('variant_color', $normalized['variant_color'] ?? null);
        $displaySize = $this->attributeValueNormalizer->normalize('variant_size', $normalized['variant_size'] ?? $normalized['size'] ?? null);
        $displayAttributes = $this->attributeValueNormalizer->normalizeAttributes($originalAttributes);

        if (filled($displayColor)) {
            $display['display_variant_color'] = $displayColor;
            $display['variant_color'] = $displayColor;
        }

        if (filled($displaySize)) {
            $display['display_size'] = $displaySize;
            $display['variant_size'] = $displaySize;
            $display['size'] = $displaySize;
        }

        if ($displayAttributes !== []) {
            $display['display_variant_attributes'] = $displayAttributes;
            $display['variant_attributes'] = $displayAttributes;
        }

        foreach (['measure', 'capacity', 'material', 'option_name'] as $field) {
            $normalizedValue = $this->attributeValueNormalizer->normalize($field, $normalized[$field] ?? null);
            if (filled($normalizedValue)) {
                $display['display_' . $field] = $normalizedValue;
                $display[$field] = $normalizedValue;
            }
        }

        $display['variant_attributes'] = array_filter(array_merge(
            is_array($display['variant_attributes'] ?? null) ? $display['variant_attributes'] : [],
            [
                'size' => $display['size'] ?? null,
                'measure' => $display['display_measure'] ?? data_get($display, 'variant_attributes.measure'),
                'capacity' => $display['display_capacity'] ?? data_get($display, 'variant_attributes.capacity'),
                'material' => $display['display_material'] ?? data_get($display, 'variant_attributes.material'),
                'option' => data_get($display, 'variant_attributes.option'),
            ]
        ), fn ($value) => !is_null($value) && $value !== '');

        return $display;
    }

    private function resolvePozitronListPrice(array $rawRow, array $orderedFields, array &$warnings, string $scope): ?float
    {
        $resolved = null;
        $resolvedField = null;
        $resolvedValues = [];

        foreach ($orderedFields as $field) {
            $value = $this->toDecimal($rawRow[$field] ?? null);
            if ($value !== null) {
                $resolvedValues[$field] = $value;
            }

            if ($resolved === null && $value !== null) {
                $resolved = $value;
                $resolvedField = $field;
            }
        }

        if (
            isset($resolvedValues['fiyat'], $resolvedValues['fiyat_normal'])
            && abs($resolvedValues['fiyat'] - $resolvedValues['fiyat_normal']) >= 0.01
        ) {
            $warnings[] = sprintf(
                'Pozitron %s fiyat alanlarında fark var; %s liste fiyatı olarak kullanıldı.',
                $scope === 'variant' ? 'varyant' : 'ürün',
                $resolvedField ?? 'uygun alan'
            );
        }

        return $resolved;
    }

    private function applyEtkinNormalization(array $rawRow, array $normalized, array &$warnings): array
    {
        $price = $this->toDecimal($rawRow['urun_fiyat'] ?? $normalized['purchase_price'] ?? null);
        $priceDisplay = $rawRow['urun_fiyat_virgul'] ?? $normalized['purchase_price_display'] ?? null;
        $displayDecimal = $this->toDecimal($priceDisplay);

        $normalized['purchase_price'] = $price ?? ($displayDecimal ?? 0.0);
        $normalized['list_price'] = $this->resolveHighestNonZeroPrice([
            $price,
            $displayDecimal,
            $normalized['list_price'] ?? null,
        ]);
        $normalized['purchase_price_display'] = $priceDisplay;
        $normalized['supplier_product_id'] = $normalized['supplier_product_id'] ?? ($rawRow['urun_id'] ?? null);
        $normalized['parent_supplier_product_id'] = $normalized['parent_supplier_product_id'] ?? ($rawRow['urun_id'] ?? null);
        $normalized['supplier_group_code'] = $this->firstFilled([
            $rawRow['urun_kodgrup'] ?? null,
            $rawRow['urun_grupkodu'] ?? null,
            $normalized['supplier_group_code'] ?? null,
        ]);
        $normalized['supplier_product_code'] = $this->firstFilled([
            $rawRow['urun_kodu'] ?? null,
            $normalized['supplier_product_code'] ?? null,
        ]);
        $normalized['product_name'] = $this->firstFilled([
            $rawRow['urun_isim'] ?? null,
            $rawRow['urun_adi'] ?? null,
            $rawRow['urun_baslik'] ?? null,
            $normalized['product_name'] ?? null,
            $normalized['display_product_name'] ?? null,
            $normalized['supplier_product_code'] ?? null,
        ]);
        $normalized['display_product_name'] = $this->firstFilled([
            $rawRow['urun_baslik'] ?? null,
            $normalized['display_product_name'] ?? null,
            $normalized['product_name'] ?? null,
        ]);
        $normalized['supplier_category_name'] = $this->firstFilled([
            $rawRow['kategori_adi'] ?? null,
            $rawRow['urun_kategori'] ?? null,
            $rawRow['category_name'] ?? null,
            $normalized['supplier_category_name'] ?? null,
        ]);
        $normalized['product_url'] = $normalized['product_url'] ?? ($rawRow['urun_trase'] ?? null);
        $normalized['detail_url'] = $normalized['detail_url'] ?? ($rawRow['urun_trase'] ?? null);
        $normalized['artwork_template_url'] = $normalized['artwork_template_url'] ?? ($rawRow['urun_trase'] ?? null);
        $normalized['artwork_template_file_name'] = $normalized['artwork_template_file_name'] ?? ($rawRow['urun_trase_dosya_isim'] ?? null);
        $normalized['artwork_template_file_size'] = $normalized['artwork_template_file_size'] ?? ($rawRow['urun_trase_dosya_boyut'] ?? null);
        $normalized['supplier_hash'] = $normalized['supplier_hash'] ?? ($rawRow['md5'] ?? null);
        $normalized['currency'] = $normalized['currency'] ?? 'TL';
        $normalized['list_price'] = $normalized['list_price'] ?? null;
        $normalized['price_policy_warning'] = (bool) ($normalized['price_policy_warning'] ?? false);
        $normalized['pricing_policy_type'] = $normalized['pricing_policy_type'] ?? 'list_price_only';
        $normalized['variant_size'] = $this->firstFilled([
            $rawRow['urun_ebat'] ?? null,
            $normalized['variant_size'] ?? null,
            $normalized['size'] ?? null,
        ]);
        $normalized['size'] = $normalized['size'] ?? $normalized['variant_size'];
        $normalized['stock_quantity'] = $normalized['stock_quantity'] ?? $this->toDecimal($rawRow['urun_stok'] ?? $rawRow['toplam_stok'] ?? null);
        $normalized['variant_stock_quantity'] = $normalized['variant_stock_quantity'] ?? $this->toDecimal($rawRow['toplam_stok'] ?? $rawRow['urun_stok'] ?? null);
        $normalized['total_variant_stock_quantity'] = $normalized['total_variant_stock_quantity'] ?? $this->toDecimal($rawRow['toplam_stok'] ?? $rawRow['urun_stok'] ?? null);

        $warningFlag = $this->toBooleanFlag($rawRow['kirmiziurun'] ?? $normalized['warning_flag'] ?? false);
        $normalized['warning_flag'] = $warningFlag;
        if ($warningFlag) {
            $warnings[] = 'Etkin kirmiziurun alanı özel fiyat/iskonto uyarısı olarak işaretlendi.';
        }

        if (blank($normalized['variant_color'] ?? null)) {
            $color = $this->firstFilled([
                $rawRow['urun_renk'] ?? null,
                $this->extractColorFromText($rawRow['urun_baslik'] ?? null),
                $this->extractColorFromText($rawRow['urun_kodu'] ?? null),
                $this->extractColorFromText($rawRow['urun_isim'] ?? null),
            ]);

            if (filled($color)) {
                $normalized['variant_color'] = $color;
                $normalized['extracted_color_source'] = filled($rawRow['urun_renk'] ?? null)
                    ? 'urun_renk'
                    : (filled($this->extractColorFromText($rawRow['urun_baslik'] ?? null)) ? 'urun_baslik' : (filled($this->extractColorFromText($rawRow['urun_kodu'] ?? null)) ? 'urun_kodu' : 'urun_isim'));
                if (($normalized['extracted_color_source'] ?? null) !== 'urun_renk') {
                    $warnings[] = 'Renk bilgisi ürün adı veya parent üründen çıkarıldı.';
                }
            }
        }

        if (blank($normalized['variant_name'] ?? null)) {
            $nameParts = array_filter([
                $rawRow['urun_baslik'] ?? null,
                blank($rawRow['urun_baslik'] ?? null) ? ($rawRow['urun_isim'] ?? $normalized['product_name'] ?? null) : null,
                blank($rawRow['urun_baslik'] ?? null) ? ($rawRow['urun_renk'] ?? null) : null,
                blank($rawRow['urun_baslik'] ?? null) ? ($rawRow['urun_ebat'] ?? null) : null,
            ]);
            $normalized['variant_name'] = $nameParts ? trim(implode(' ', $nameParts)) : null;
        }

        return $normalized;
    }

    private function applyYeniNesilNormalization(array $rawRow, array $normalized, array &$warnings): array
    {
        $listPrice = $this->toDecimal($rawRow['fiyat'] ?? $normalized['list_price'] ?? $normalized['purchase_price'] ?? null);
        $alternativePrice = $this->toDecimal($rawRow['dolar_fiyat'] ?? $normalized['alternative_price'] ?? null);
        $variantStock = $this->toDecimal($rawRow['stok'] ?? $normalized['variant_stock_quantity'] ?? $normalized['stock_quantity'] ?? null);
        $totalStock = $this->toDecimal($rawRow['toplamstok'] ?? $normalized['total_variant_stock_quantity'] ?? $variantStock);

        $normalized['supplier_product_id'] = $normalized['supplier_product_id'] ?? ($rawRow['uid'] ?? null);
        $normalized['supplier_category_id'] = $normalized['supplier_category_id'] ?? ($rawRow['kid'] ?? null);
        $normalized['supplier_category_level'] = $normalized['supplier_category_level'] ?? ($rawRow['kid_seviye'] ?? null);
        $normalized['supplier_category_name'] = $this->firstFilled([
            $rawRow['kategori'] ?? null,
            $normalized['supplier_category_name'] ?? null,
        ]);
        $normalized['product_name'] = $this->firstFilled([
            $rawRow['isim'] ?? null,
            $rawRow['baslik'] ?? null,
            $normalized['product_name'] ?? null,
            $normalized['display_product_name'] ?? null,
            $normalized['supplier_product_code'] ?? null,
        ]);
        $normalized['display_product_name'] = $this->firstFilled([
            $rawRow['baslik'] ?? null,
            $normalized['display_product_name'] ?? null,
            $normalized['product_name'] ?? null,
        ]);
        $normalized['list_price'] = $this->resolveHighestNonZeroPrice([$listPrice]);
        $normalized['alternative_price'] = $alternativePrice;
        $normalized['usd_price'] = $normalized['usd_price'] ?? $alternativePrice;
        $normalized['purchase_price'] = null;
        $normalized['price_policy_warning'] = false;
        $normalized['pricing_policy_type'] = 'list_price_only';
        $normalized['currency'] = $normalized['currency'] ?? 'TL';
        $normalized['vat_rate'] = $this->toDecimal($rawRow['kdv'] ?? $normalized['vat_rate'] ?? null);
        $normalized['variant_stock_quantity'] = $variantStock;
        $normalized['stock_quantity'] = $variantStock;
        $normalized['total_variant_stock_quantity'] = $totalStock;
        $normalized['variant_size'] = $this->firstFilled([
            $rawRow['ebat'] ?? null,
            $normalized['variant_size'] ?? null,
            $normalized['size'] ?? null,
        ]);
        $normalized['size'] = $normalized['variant_size'] ?? $normalized['size'] ?? null;
        $normalized['product_url'] = $this->normalizeHttpUrl($rawRow['sayfa'] ?? $normalized['product_url'] ?? null);
        $normalized['detail_url'] = $this->normalizeHttpUrl($normalized['detail_url'] ?? null);

        if (filled($listPrice)) {
            $warnings[] = 'Yeni Nesil fiyat alanı liste fiyatı olarak yorumlandı.';
        }

        return $normalized;
    }

    private function applyIlpenNormalization(array $rawRow, array $normalized, array &$warnings): array
    {
        $colorData = $this->extractIlpenVariantColor($rawRow);
        if (filled($colorData['color'] ?? null)) {
            $normalized['variant_color'] = $normalized['variant_color'] ?? $colorData['color'];
            if (blank($normalized['extracted_color_source'] ?? null)) {
                $normalized['extracted_color_source'] = $colorData['source'];
            }
            if (!empty($colorData['warning'])) {
                $warnings[] = $colorData['warning'];
            }
        }

        if (filled($rawRow['KategoriSub'] ?? null)) {
            $normalized['supplier_category_name'] = trim((string) $rawRow['KategoriSub']);
        } elseif (filled($rawRow['KategoriMain'] ?? null)) {
            $normalized['supplier_category_name'] = trim((string) $rawRow['KategoriMain']);
        }

        if (filled($rawRow['IndirimliFiyat'] ?? null) && blank($normalized['discounted_price'] ?? null)) {
            $normalized['discounted_price'] = $this->toDecimal($rawRow['IndirimliFiyat']);
        }

        $normalized['currency'] = $normalized['currency'] ?? ($rawRow['ParaBirimi'] ?? null);
        $normalized['vat_rate'] = $normalized['vat_rate'] ?? $this->toDecimal($rawRow['KdvOrani'] ?? null);
        $normalized['purchase_price'] = $this->toDecimal($rawRow['AlisFiyati'] ?? $normalized['purchase_price'] ?? null);
        $normalized['list_price'] = $this->resolveHighestNonZeroPrice([
            $this->toDecimal($rawRow['SatisFiyati'] ?? null),
            $this->toDecimal($rawRow['IndirimliFiyat'] ?? null),
            $this->toDecimal($rawRow['AlisFiyati'] ?? null),
            $normalized['list_price'] ?? null,
        ]);
        $variantStock = $this->toDecimal($rawRow['StokAdedi'] ?? $normalized['variant_stock_quantity'] ?? null);
        $totalStock = $this->toDecimal($rawRow['TumVaryantToplamStokAdedi'] ?? $normalized['total_variant_stock_quantity'] ?? null);
        $normalized['variant_stock_quantity'] = $variantStock;
        $normalized['total_variant_stock_quantity'] = $totalStock;
        $normalized['stock_quantity'] = $totalStock ?? $variantStock ?? $normalized['stock_quantity'] ?? null;
        $normalized['pricing_policy_type'] = $normalized['pricing_policy_type'] ?? 'list_price_only';
        if (blank($normalized['purchase_price']) && filled($normalized['list_price'] ?? null)) {
            $normalized['price_policy_warning'] = true;
            $warnings[] = 'İlpen fiyat alanı tedarikçi yapısına göre kontrol edilmelidir.';
        }

        return $normalized;
    }

    private function applyAkdenizPricing(array $rawRow, array $normalized, array &$warnings): array
    {
        $netPrice = $this->toDecimal($rawRow['netfiyat'] ?? $normalized['net_price'] ?? $normalized['purchase_price'] ?? null);
        $listPrice = $this->toDecimal($rawRow['listefiyati'] ?? $normalized['list_price'] ?? null);
        $closedListPrice = $this->toDecimal($rawRow['listefiyatkapali'] ?? $normalized['closed_list_price'] ?? $normalized['alternative_price'] ?? null);
        $discountRate = $this->toDecimal($rawRow['iskonto'] ?? $normalized['discount_rate'] ?? null);
        $resolvedListPrice = $this->resolveHighestNonZeroPrice([
            $listPrice,
            $closedListPrice,
            $netPrice,
        ]);
        $hasEqualPrices = $this->pricesAreEqual($resolvedListPrice, $netPrice);
        $hasZeroDiscount = $discountRate !== null && abs((float) $discountRate) < 0.0001;
        $discatName = (string) ($rawRow['discat_name'] ?? '');
        $priceNote = (string) ($rawRow['fiyataciklamasi'] ?? '');
        $hasNetCategoryHint = filled($discatName) && str_contains(mb_strtoupper($discatName, 'UTF-8'), 'NET');
        $hasPriceCallHint = filled($priceNote) && str_contains(mb_strtolower($priceNote, 'UTF-8'), 'fiyat alınız');
        $hasClosedListZero = $closedListPrice !== null && abs((float) $closedListPrice) < 0.0001;
        $netPriceWarning = ($resolvedListPrice && $resolvedListPrice > 0 && $netPrice && $netPrice > 0 && $hasEqualPrices)
            || ($hasZeroDiscount && $hasEqualPrices)
            || $hasNetCategoryHint
            || $hasPriceCallHint
            || ($hasClosedListZero && $hasEqualPrices);

        $normalized['net_price'] = $netPrice;
        $normalized['list_price'] = $resolvedListPrice;
        $normalized['closed_list_price'] = $closedListPrice;
        $normalized['discount_rate'] = $discountRate;
        $normalized['currency'] = $normalized['currency'] ?? ($rawRow['kur'] ?? null);
        $normalized['vat_rate'] = $this->toDecimal($rawRow['kdvorani'] ?? $normalized['vat_rate'] ?? null);
        $normalized['purchase_price'] = $netPrice && $netPrice > 0 ? $netPrice : null;
        $normalized['supplier_gross_list_price'] = $resolvedListPrice;
        $normalized['supplier_gross_list_price_source_field'] = $listPrice && $listPrice > 0
            ? 'listefiyati'
            : ($closedListPrice && $closedListPrice > 0 ? 'listefiyatkapali' : null);
        $normalized['supplier_net_price'] = $netPrice;
        $normalized['supplier_feed_discount_rate'] = $discountRate;
        $normalized['supplier_net_price_source_field'] = $netPrice && $netPrice > 0 ? 'netfiyat' : null;
        $normalized['net_price_warning'] = $netPriceWarning;
        $normalized['price_policy_warning'] = $netPriceWarning || !($netPrice && $netPrice > 0);
        $normalized['pricing_policy_type'] = $netPriceWarning ? 'net_price' : 'discounted_list_price';

        if ($netPriceWarning) {
            $warnings[] = 'Bu ürün net fiyatlı olabilir. Teklif/sipariş sırasında standart iskonto uygulanmamalı; gerekirse birim satış fiyatı artırılarak çalışılmalıdır.';
        } elseif ($netPrice && $netPrice > 0) {
            $warnings[] = 'Akdeniz liste fiyatı satış referansı olarak kullanıldı.';
        } else {
            $warnings[] = 'Akdeniz liste fiyatı satış referansı olarak kullanıldı.';
        }

        return $normalized;
    }

    private function applySupplierWarningNormalization(array $rawRow, array $normalized, array &$warnings): array
    {
        $hasSupplierWarning = $this->toBooleanFlag(
            $rawRow['urun_kirmizi']
                ?? $rawRow['kirmiziurun']
                ?? $rawRow['urun_turuncu']
                ?? $rawRow['turuncu']
                ?? $normalized['warning_flag']
                ?? false
        );

        if (!$hasSupplierWarning) {
            $normalized['supplier_warning_flag'] = (bool) ($normalized['supplier_warning_flag'] ?? false);

            return $normalized;
        }

        $normalized['warning_flag'] = true;
        $normalized['supplier_warning_flag'] = true;
        $normalized['supplier_warning_type'] = $normalized['supplier_warning_type'] ?? 'supplier_special_price_warning';
        $normalized['price_policy_warning'] = true;

        if (!collect($warnings)->contains(fn (string $warning) => str_contains($warning, 'özel fiyat/iskonto uyarılı'))) {
            $warnings[] = $this->warningMessage('supplier_special_price_warning');
        }

        return $normalized;
    }

    private function pricesAreEqual(?float $left, ?float $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }

        return abs($left - $right) < 0.01;
    }

    private function resolveHighestNonZeroPrice(array $candidates): ?float
    {
        $prices = collect($candidates)
            ->map(fn ($value) => $this->toDecimal($value))
            ->filter(fn ($value) => $value !== null && $value > 0)
            ->values();

        if ($prices->isEmpty()) {
            return null;
        }

        return (float) $prices->max();
    }

    private function normalizeHttpUrl(mixed $value): ?string
    {
        if (!filled($value)) {
            return null;
        }

        $url = trim((string) $value);

        if (!preg_match('/^https?:\/\//i', $url)) {
            return null;
        }

        return $url;
    }

    private function normalizeSourceUrl(SupplierSource $source, mixed $value): ?string
    {
        if (!filled($value)) {
            return null;
        }

        $url = trim((string) $value);
        if ($url === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        $base = $source->url ?? null;
        if (!filled($base) || !preg_match('/^https?:\/\//i', (string) $base)) {
            return $url;
        }

        $parts = parse_url((string) $base);
        if (!is_array($parts) || blank($parts['scheme'] ?? null) || blank($parts['host'] ?? null)) {
            return null;
        }

        $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
        if (filled($parts['port'] ?? null)) {
            $origin .= ':' . $parts['port'];
        }

        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }

        $path = $parts['path'] ?? '/';
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
        $directory = $directory === '.' ? '' : $directory;

        return $origin . ($directory !== '' ? $directory : '') . '/' . ltrim($url, '/');
    }

    private function effectiveProfileSettings(SupplierSource $source, string $profileKey): array
    {
        $profile = $this->fieldDictionary->getSupplierProfile($profileKey);
        $config = (array) ($source->config ?? []);

        if ($profileKey === 'CUSTOM') {
            $profile['supplier_code_prefix'] = $config['supplier_prefix'] ?? $profile['supplier_code_prefix'] ?? $this->deriveFallbackPrefix($source);
            $profile['generated_code_template'] = $config['generated_code_template'] ?? $profile['generated_code_template'] ?? '{PREFIX}-{SUPPLIER_PRODUCT_CODE}';
            $profile['generated_variant_code_template'] = $config['generated_variant_code_template'] ?? $profile['generated_variant_code_template'] ?? '{PREFIX}-{VARIANT_STOCK_CODE}';
            $profile['generated_name_template'] = $config['generated_name_template'] ?? $profile['generated_name_template'] ?? '{PRODUCT_NAME}';
            $profile['product_model'] = $config['product_model'] ?? $profile['product_model'] ?? 'flat_single_row';
            $profile['product_node_path'] = $config['product_node_path'] ?? $profile['product_node_path'] ?? null;
        }

        return $profile;
    }

    private function deriveFallbackPrefix(SupplierSource $source): string
    {
        $seed = $source->supplier?->code ?: $source->supplier?->name ?: $source->source_name;
        $normalized = $this->productCodeNormalizer->normalizeCode((string) $seed);

        return substr($normalized, 0, 2) ?: 'PD';
    }

    private function buildVariantGalleryImages(?string $variantImage, array $productGalleryImages): array
    {
        return collect([$variantImage, ...$productGalleryImages])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => trim((string) $value))
            ->unique()
            ->values()
            ->all();
    }

    private function applyProductPayloadFallbacks(array $payload, array $rawRow, string $profileKey): array
    {
        if ($profileKey !== 'ETKIN') {
            return $payload;
        }

        $generatedCodeSource = $this->firstFilled([
            $rawRow['urun_kodgrup'] ?? null,
            $rawRow['urun_grupkodu'] ?? null,
            $payload['supplier_group_code'] ?? null,
            $rawRow['urun_kodu'] ?? null,
            $payload['supplier_product_code'] ?? null,
            $rawRow['urun_id'] ?? null,
            $rawRow['_root_key'] ?? null,
        ]);

        if (($payload['generated_product_code'] ?? '') === 'ET' && filled($generatedCodeSource)) {
            $payload['generated_product_code'] = $this->productCodeNormalizer->applySupplierPrefix('ET', (string) $generatedCodeSource);

            if (blank($rawRow['urun_kodgrup'] ?? null) && blank($rawRow['urun_grupkodu'] ?? null) && blank($payload['supplier_group_code'] ?? null)) {
                $payload['warnings'][] = 'Grup kodu bulunamadı, ürün kodu ile generated code üretildi.';
            }
        }

        if (blank($payload['supplier_group_code'] ?? null) && filled($generatedCodeSource)) {
            $payload['supplier_group_code'] = (string) $generatedCodeSource;
        }

        $productNameFallback = $this->firstFilled([
            $rawRow['urun_isim'] ?? null,
            $rawRow['urun_adi'] ?? null,
            $rawRow['urun_baslik'] ?? null,
            $payload['product_name'] ?? null,
            $payload['base_product_name'] ?? null,
            $payload['supplier_product_code'] ?? null,
            $payload['generated_product_code'] ?? null,
        ]);

        if (filled($productNameFallback) && blank($rawRow['urun_isim'] ?? null) && blank($rawRow['urun_adi'] ?? null)) {
            $payload['product_name'] = $productNameFallback;
            $payload['warnings'][] = 'Ürün adı fallback alanından üretildi.';
        }

        if (blank($payload['supplier_category_name'] ?? null)) {
            $payload['supplier_category_name'] = $this->firstFilled([
                $rawRow['kategori_adi'] ?? null,
                $rawRow['urun_kategori'] ?? null,
                $rawRow['category_name'] ?? null,
            ]);
        }

        $payload['warnings'] = array_values(array_unique(array_filter($payload['warnings'] ?? [])));

        return $payload;
    }

    private function applyVariantPayloadFallbacks(array $variant, array $rawRow, array $product, string $profileKey): array
    {
        if ($profileKey !== 'ETKIN') {
            return $variant;
        }

        if (blank($variant['variant_color'] ?? null)) {
            $fallbackColor = $this->firstFilled([
                $rawRow['urun_renk'] ?? null,
                $product['color'] ?? null,
                $this->extractColorFromText($rawRow['urun_baslik'] ?? null),
                $this->extractColorFromText($rawRow['urun_kodu'] ?? null),
            ]);

            if (filled($fallbackColor)) {
                $variant['variant_color'] = $fallbackColor;
                $variant['normalized_payload']['variant_color'] = $fallbackColor;
                $variant['extracted_color_source'] = filled($rawRow['urun_renk'] ?? null)
                    ? 'urun_renk'
                    : (!empty($product['color']) ? 'parent_urun_renk' : (filled($this->extractColorFromText($rawRow['urun_baslik'] ?? null)) ? 'urun_baslik' : 'urun_kodu'));
                $variant['normalized_payload']['extracted_color_source'] = $variant['extracted_color_source'];
                if (($variant['extracted_color_source'] ?? null) !== 'urun_renk') {
                    $variant['warnings'][] = 'Renk bilgisi ürün adı veya parent üründen çıkarıldı.';
                }
            }
        }

        if (blank($variant['variant_size'] ?? null)) {
            $variant['variant_size'] = $this->firstFilled([
                $rawRow['urun_ebat'] ?? null,
                $variant['normalized_payload']['variant_size'] ?? null,
                $variant['normalized_payload']['size'] ?? null,
            ]);
        }

        if (blank($variant['variant_name'] ?? null)) {
            $variant['variant_name'] = $this->firstFilled([
                $rawRow['urun_baslik'] ?? null,
                $rawRow['urun_isim'] ?? null,
                $product['product_name'] ?? null,
                $variant['variant_stock_code'] ?? null,
            ]);
        }

        $variant['warnings'] = array_values(array_unique(array_filter($variant['warnings'] ?? [])));

        return $variant;
    }

    private function applyAkdenizColorExtraction(array $rawRow, array $normalized, array &$warnings): array
    {
        if (filled($normalized['variant_color'] ?? null)) {
            return $normalized;
        }

        $candidates = [
            'urunattradi' => $rawRow['urunattradi'] ?? null,
            'urunadi' => $rawRow['urunadi'] ?? null,
            'pure_prodname' => $rawRow['pure_prodname'] ?? null,
            'urunkodu' => $rawRow['urunkodu'] ?? $normalized['supplier_product_code'] ?? null,
        ];

        foreach ($candidates as $sourceField => $text) {
            $color = $this->extractColorFromText($text);

            if (!filled($color)) {
                continue;
            }

            $normalized['variant_color'] = $color;
            $normalized['extracted_color_source'] = $sourceField;
            $warnings[] = 'Renk bilgisi urunattradi/ürün adından çıkarıldı.';

            return $normalized;
        }

        return $normalized;
    }

    private function extractColorFromText(?string $text): ?string
    {
        if (blank($text)) {
            return null;
        }

        $colorMap = [
            'SIYAH' => 'Siyah',
            'BEYAZ' => 'Beyaz',
            'KIRMIZI' => 'Kırmızı',
            'LACIVERT' => 'Lacivert',
            'MAVI' => 'Mavi',
            'YESIL' => 'Yeşil',
            'SARI' => 'Sarı',
            'TURUNCU' => 'Turuncu',
            'MOR' => 'Mor',
            'PEMBE' => 'Pembe',
            'GRI' => 'Gri',
            'FUME' => 'Füme',
            'KAHVERENGI' => 'Kahverengi',
            'KREM' => 'Krem',
            'SEFFAF' => 'Şeffaf',
            'ALTIN' => 'Altın',
            'GUMUS' => 'Gümüş',
            'TURKUAZ' => 'Turkuaz',
            'BORDO' => 'Bordo',
        ];

        $normalizedText = mb_strtoupper(strtr((string) $text, [
            'ç' => 'c', 'Ç' => 'C',
            'ğ' => 'g', 'Ğ' => 'G',
            'ı' => 'i', 'İ' => 'I',
            'ö' => 'o', 'Ö' => 'O',
            'ş' => 's', 'Ş' => 'S',
            'ü' => 'u', 'Ü' => 'U',
        ]), 'UTF-8');

        foreach ($colorMap as $needle => $label) {
            if (preg_match('/(^|[^A-Z])' . preg_quote($needle, '/') . '([^A-Z]|$)/u', $normalizedText)) {
                return $label;
            }
        }

        return null;
    }

    private function extractIlpenVariantColor(array $rawRow): array
    {
        $option = $rawRow['EkSecenekOzellik'] ?? null;
        $candidates = [];

        if (is_array($option)) {
            $candidates = array_merge($candidates, $this->extractIlpenColorCandidatesFromOptions($option));
        }

        $candidates[] = ['source' => 'StokKodu', 'value' => $rawRow['StokKodu'] ?? null];
        $candidates[] = ['source' => 'UrunAdi', 'value' => $rawRow['UrunAdi'] ?? null];

        foreach ($candidates as $candidate) {
            $color = $this->extractColorFromText($candidate['value'] ?? null);

            if (!filled($color)) {
                continue;
            }

            $source = (string) ($candidate['source'] ?? 'variant');
            $warning = in_array($source, ['StokKodu', 'UrunAdi'], true)
                ? 'Renk bilgisi varyasyon özelliği/stok kodundan çıkarıldı.'
                : 'Renk bilgisi varyasyon özelliği/stok kodundan çıkarıldı.';

            return [
                'color' => $color,
                'source' => $source,
                'warning' => $warning,
            ];
        }

        return [
            'color' => null,
            'source' => null,
            'warning' => null,
        ];
    }

    private function extractIlpenColorCandidatesFromOptions(array $option): array
    {
        $options = $option['Ozellik'] ?? $option;

        if (is_array($options) && !array_is_list($options)) {
            $options = [$options];
        }

        $candidates = [];

        foreach (($options ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $attributes = $entry['_attributes'] ?? [];
            $label = $attributes['Tanim'] ?? $attributes['tanim'] ?? null;

            if (!filled($label) || mb_strtolower((string) $label, 'UTF-8') !== 'renk') {
                continue;
            }

            $candidates[] = [
                'source' => 'EkSecenekOzellik.Ozellik[Tanim=Renk].Deger',
                'value' => $attributes['Deger'] ?? $attributes['deger'] ?? null,
            ];
            $candidates[] = [
                'source' => 'EkSecenekOzellik.Ozellik[Tanim=Renk]',
                'value' => $entry['_value'] ?? null,
            ];
        }

        return $candidates;
    }

    private function warningMessage(string $key): string
    {
        return config('prodelya_product_data_hub.preview_warning_messages.' . $key, $key);
    }

    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function firstFilledField(array $row, array $fields): ?string
    {
        foreach ($fields as $field) {
            if (filled(data_get($row, $field))) {
                return $field;
            }
        }

        return null;
    }

    private function shouldEnrichGalleryFromProductPage(SupplierSource $source, array $productPayload): bool
    {
        if (!$this->allowGalleryEnrichment) {
            return false;
        }

        if (!(bool) ($source->config['enrich_gallery_from_product_page'] ?? false)) {
            return false;
        }

        if ($this->galleryEnrichmentCount >= $this->galleryEnrichmentLimit) {
            return false;
        }

        return filled($productPayload['product_url'] ?? $productPayload['detail_url'] ?? null);
    }
}
