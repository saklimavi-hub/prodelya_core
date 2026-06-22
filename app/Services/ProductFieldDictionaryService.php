<?php

namespace App\Services;

use Illuminate\Support\Str;

class ProductFieldDictionaryService
{
    public function getStandardFields(): array
    {
        return [
            'supplier_product_id' => ['label' => 'Tedarikçi Ürün ID', 'type' => 'text', 'required' => false],
            'parent_supplier_product_id' => ['label' => 'Ana Tedarikçi Ürün ID', 'type' => 'text', 'required' => false],
            'supplier_product_code' => ['label' => 'Ürün Kodu', 'type' => 'text', 'required' => true],
            'supplier_group_code' => ['label' => 'Grup Kodu', 'type' => 'text', 'required' => false],
            'supplier_category_id' => ['label' => 'Tedarikçi Kategori ID', 'type' => 'text', 'required' => false],
            'supplier_category_level' => ['label' => 'Tedarikçi Kategori Seviyesi', 'type' => 'text', 'required' => false],
            'supplier_page' => ['label' => 'Tedarikçi Sayfası', 'type' => 'text', 'required' => false],
            'supplier_status' => ['label' => 'Tedarikçi Durumu', 'type' => 'text', 'required' => false],
            'variant_id' => ['label' => 'Varyasyon ID', 'type' => 'text', 'required' => false],
            'variant_code' => ['label' => 'Varyasyon Kodu', 'type' => 'text', 'required' => false],
            'variant_name' => ['label' => 'Varyasyon Adı', 'type' => 'text', 'required' => false],
            'variant_color' => ['label' => 'Varyasyon Rengi', 'type' => 'text', 'required' => false],
            'variant_size' => ['label' => 'Varyasyon Ebatı', 'type' => 'text', 'required' => false],
            'variant_attributes' => ['label' => 'Varyasyon Özellikleri', 'type' => 'json', 'required' => false],
            'variant_stock_code' => ['label' => 'Varyasyon Stok Kodu', 'type' => 'text', 'required' => false],
            'variant_stock_quantity' => ['label' => 'Varyasyon Stok Miktarı', 'type' => 'number', 'required' => false],
            'variant_image_url' => ['label' => 'Varyasyon Görseli', 'type' => 'text', 'required' => false],
            'product_name' => ['label' => 'Ürün Adı', 'type' => 'text', 'required' => true],
            'base_product_name' => ['label' => 'Ana Ürün Adı', 'type' => 'text', 'required' => false],
            'display_product_name' => ['label' => 'Görünen Ürün Adı', 'type' => 'text', 'required' => false],
            'supplier_category_name' => ['label' => 'Tedarikçi Kategorisi', 'type' => 'text', 'required' => false],
            'supplier_subcategory_name' => ['label' => 'Tedarikçi Alt Kategorisi', 'type' => 'text', 'required' => false],
            'description' => ['label' => 'Açıklama', 'type' => 'text', 'required' => false],
            'product_url' => ['label' => 'Ürün Sayfası Linki', 'type' => 'text', 'required' => false],
            'detail_url' => ['label' => 'Detay Linki', 'type' => 'text', 'required' => false],
            'artwork_template_url' => ['label' => 'Trase / Şablon Linki', 'type' => 'text', 'required' => false],
            'artwork_template_file_name' => ['label' => 'Trase Dosya Adı', 'type' => 'text', 'required' => false],
            'artwork_template_file_size' => ['label' => 'Trase Dosya Boyutu', 'type' => 'text', 'required' => false],
            'purchase_price' => ['label' => 'Ham Alış Fiyatı', 'type' => 'decimal', 'required' => false],
            'net_price' => ['label' => 'Net Fiyat', 'type' => 'decimal', 'required' => false],
            'list_price' => ['label' => 'Liste Fiyatı', 'type' => 'decimal', 'required' => false],
            'closed_list_price' => ['label' => 'Kapalı Liste Fiyatı', 'type' => 'decimal', 'required' => false],
            'discounted_price' => ['label' => 'İndirimli Fiyat', 'type' => 'decimal', 'required' => false],
            'purchase_price_display' => ['label' => 'Alış Fiyatı Görünümü', 'type' => 'text', 'required' => false],
            'discount_rate' => ['label' => 'İskonto Oranı', 'type' => 'decimal', 'required' => false],
            'alternative_price' => ['label' => 'Alternatif Fiyat', 'type' => 'decimal', 'required' => false],
            'usd_price' => ['label' => 'USD Fiyat', 'type' => 'decimal', 'required' => false],
            'eur_price' => ['label' => 'EUR Fiyat', 'type' => 'decimal', 'required' => false],
            'currency' => ['label' => 'Para Birimi', 'type' => 'text', 'required' => false],
            'vat_rate' => ['label' => 'KDV Oranı', 'type' => 'decimal', 'required' => false],
            'stock_quantity' => ['label' => 'Stok Miktarı', 'type' => 'number', 'required' => false],
            'total_variant_stock_quantity' => ['label' => 'Toplam Varyasyon Stoku', 'type' => 'number', 'required' => false],
            'parent_image_url' => ['label' => 'Ana Ürün Görseli', 'type' => 'text', 'required' => false],
            'image_url' => ['label' => 'Ana Görsel', 'type' => 'text', 'required' => false],
            'image_source_field' => ['label' => 'Ana Görsel Kaynağı', 'type' => 'text', 'required' => false],
            'gallery_images' => ['label' => 'Galeri Görselleri', 'type' => 'json', 'required' => false],
            'gallery_source_fields' => ['label' => 'Galeri Alanları', 'type' => 'json', 'required' => false],
            'gallery_image_1' => ['label' => 'Galeri Görseli 1', 'type' => 'text', 'required' => false],
            'gallery_image_2' => ['label' => 'Galeri Görseli 2', 'type' => 'text', 'required' => false],
            'gallery_image_3' => ['label' => 'Galeri Görseli 3', 'type' => 'text', 'required' => false],
            'gallery_image_4' => ['label' => 'Galeri Görseli 4', 'type' => 'text', 'required' => false],
            'gallery_image_5' => ['label' => 'Galeri Görseli 5', 'type' => 'text', 'required' => false],
            'gallery_image_6' => ['label' => 'Galeri Görseli 6', 'type' => 'text', 'required' => false],
            'gallery_image_7' => ['label' => 'Galeri Görseli 7', 'type' => 'text', 'required' => false],
            'gallery_image_8' => ['label' => 'Galeri Görseli 8', 'type' => 'text', 'required' => false],
            'gallery_image_9' => ['label' => 'Galeri Görseli 9', 'type' => 'text', 'required' => false],
            'gallery_image_10' => ['label' => 'Galeri Görsel 10', 'type' => 'text', 'required' => false],
            'gallery_image_11' => ['label' => 'Galeri Görsel 11', 'type' => 'text', 'required' => false],
            'gallery_image_12' => ['label' => 'Galeri Görsel 12', 'type' => 'text', 'required' => false],
            'gallery_image_13' => ['label' => 'Galeri Görsel 13', 'type' => 'text', 'required' => false],
            'image_fallback_used' => ['label' => 'Görsel Fallback Kullanıldı', 'type' => 'boolean', 'required' => false],
            'warning_flag' => ['label' => 'Özel Uyarı', 'type' => 'boolean', 'required' => false],
            'supplier_warning_flag' => ['label' => 'Tedarikçi Uyarı Bayrağı', 'type' => 'boolean', 'required' => false],
            'supplier_warning_type' => ['label' => 'Tedarikçi Uyarı Tipi', 'type' => 'text', 'required' => false],
            'price_policy_warning' => ['label' => 'Fiyat Politikası Uyarısı', 'type' => 'boolean', 'required' => false],
            'net_price_warning' => ['label' => 'Net Fiyat Uyarısı', 'type' => 'boolean', 'required' => false],
            'pricing_policy_type' => ['label' => 'Fiyat Politikası Tipi', 'type' => 'text', 'required' => false],
            'size' => ['label' => 'Ebat', 'type' => 'text', 'required' => false],
            'color' => ['label' => 'Renk', 'type' => 'text', 'required' => false],
            'measure' => ['label' => 'Ölçü', 'type' => 'text', 'required' => false],
            'option_name' => ['label' => 'Opsiyon Adı', 'type' => 'text', 'required' => false],
            'production_flag' => ['label' => 'Üretim Bayrağı', 'type' => 'boolean', 'required' => false],
            'print_price_info_html' => ['label' => 'Baskı Fiyat Bilgisi', 'type' => 'html', 'required' => false],
            'supplier_tags' => ['label' => 'Tedarikçi Etiketleri', 'type' => 'text', 'required' => false],
            'supplier_hash' => ['label' => 'Tedarikçi Hash', 'type' => 'text', 'required' => false],
        ];
    }

    public function getSupplierProfile(?string $supplierKey): array
    {
        if (blank($supplierKey)) {
            return [];
        }

        return config('prodelya_product_data_hub.supplier_profiles.' . $supplierKey, []);
    }

    public function getSourceFields(?string $supplierKey = null): array
    {
        if ($supplierKey !== null) {
            return $this->getSupplierProfile($supplierKey)['source_fields'] ?? [];
        }

        return collect(config('prodelya_product_data_hub.supplier_profiles', []))
            ->pluck('source_fields')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function getSourceFieldAliases(?string $supplierKey = null): array
    {
        if ($supplierKey !== null) {
            return $this->getSupplierProfile($supplierKey)['field_aliases'] ?? [];
        }

        $aliases = [];

        foreach (config('prodelya_product_data_hub.supplier_profiles', []) as $profile) {
            foreach (($profile['field_aliases'] ?? []) as $sourceField => $standardField) {
                $aliases[$sourceField] = $standardField;
            }
        }

        return $aliases;
    }

    public function normalizeSourceField(string $field, ?string $supplierKey = null): string
    {
        $normalized = Str::of($field)
            ->replace(['Ç', 'ç', 'Ğ', 'ğ', 'İ', 'I', 'ı', 'Ö', 'ö', 'Ş', 'ş', 'Ü', 'ü'], ['c', 'c', 'g', 'g', 'i', 'i', 'i', 'o', 'o', 's', 's', 'u', 'u'])
            ->replace(['[', ']', '(', ')'], ' ')
            ->replace(['.', '-', '/'], '_')
            ->replaceMatches('/\s+/', '_')
            ->snake()
            ->upper()
            ->toString();

        $aliases = $this->getSourceFieldAliases($supplierKey);

        foreach ($aliases as $sourceField => $standardField) {
            if ($this->normalizeSourceFieldWithoutAlias($sourceField) === $normalized) {
                return $standardField;
            }
        }

        return Str::lower($normalized);
    }

    public function findStandardFieldForSource(string $field, ?string $supplierKey = null): ?string
    {
        $aliases = $this->getSourceFieldAliases($supplierKey);
        $normalizedSource = $this->normalizeSourceFieldWithoutAlias($field);

        foreach ($aliases as $sourceField => $standardField) {
            if ($this->normalizeSourceFieldWithoutAlias($sourceField) === $normalizedSource) {
                return $standardField;
            }
        }

        return null;
    }

    public function suggestMappings(array $sourceFields, ?string $supplierKey = null): array
    {
        $suggestions = [];

        foreach ($sourceFields as $sourceField) {
            $standardField = $this->findStandardFieldForSource($sourceField, $supplierKey);

            $suggestions[$sourceField] = [
                'standard_field_key' => $standardField,
                'normalized_source_field' => $this->normalizeSourceField($sourceField, $supplierKey),
                'confidence_score' => $standardField ? 96.0 : 0.0,
                'mapping_status' => $standardField ? 'suggested' : 'pending',
                'legacy_field_name' => $this->normalizeSourceFieldWithoutAlias($sourceField),
            ];
        }

        return $suggestions;
    }

    public function validateRequiredMappings(array $mappings): array
    {
        $selected = collect($mappings)
            ->map(function ($mapping) {
                if (is_array($mapping)) {
                    return $mapping['standard_field_key'] ?? $mapping['target_field'] ?? null;
                }

                return $mapping;
            })
            ->filter(fn ($value) => filled($value))
            ->values()
            ->all();

        $errors = [];

        if (!$this->hasAny($selected, ['supplier_product_code', 'variant_stock_code'])) {
            $errors[] = 'supplier_product_code veya variant_stock_code eşlemesi eksik.';
        }

        if (!$this->hasAny($selected, ['product_name', 'base_product_name', 'display_product_name'])) {
            $errors[] = 'product_name veya base_product_name veya display_product_name eşlemesi eksik.';
        }

        if (!$this->hasAny($selected, ['list_price', 'purchase_price'])) {
            $errors[] = 'list_price veya purchase_price eşlemesi eksik.';
        }

        if (!$this->hasAny($selected, ['supplier_product_id', 'parent_supplier_product_id', 'supplier_product_code', 'variant_stock_code'])) {
            $errors[] = 'Update key için supplier_product_id / parent_supplier_product_id / supplier_product_code / variant_stock_code alanlarından biri gerekli.';
        }

        return $errors;
    }

    public function buildMappingStatusSummary(array $mappings): array
    {
        $requiredErrors = $this->validateRequiredMappings($mappings);

        return [
            'total' => count($mappings),
            'mapped' => collect($mappings)->filter(fn ($mapping) => filled($mapping['standard_field_key'] ?? $mapping['target_field'] ?? null))->count(),
            'required_errors' => $requiredErrors,
        ];
    }

    public function detectSupplierKey(?string $supplierCode = null, ?string $supplierName = null): ?string
    {
        $profiles = array_keys(config('prodelya_product_data_hub.supplier_profiles', []));
        $candidates = array_filter([$supplierCode, $supplierName]);

        foreach ($candidates as $candidate) {
            $normalized = Str::upper(Str::slug((string) $candidate, '-'));
            foreach ($profiles as $profileKey) {
                if ($normalized === $profileKey || str_contains($normalized, $profileKey)) {
                    return $profileKey;
                }
            }
        }

        return null;
    }

    private function hasAny(array $selected, array $keys): bool
    {
        return !empty(array_intersect($selected, $keys));
    }

    public function getMappingStatusOptions(): array
    {
        return [
            'pending' => 'Bekliyor',
            'suggested' => 'Önerildi',
            'mapped' => 'Eşlendi',
            'ignored' => 'Yok Sayıldı',
            'needs_review' => 'Kontrol Gerekli',
        ];
    }

    public function getTypeLabel(string $type): string
    {
        return match ($type) {
            'text' => 'Metin',
            'number' => 'Sayı',
            'decimal' => 'Ondalıklı Sayı',
            'boolean' => 'Evet/Hayır',
            'json' => 'JSON',
            'html' => 'HTML',
            'date' => 'Tarih',
            default => ucfirst($type),
        };
    }

    public function normalizeSourceFieldWithoutAlias(string $field): string
    {
        return Str::of($field)
            ->replace(['Ç', 'ç', 'Ğ', 'ğ', 'İ', 'I', 'ı', 'Ö', 'ö', 'Ş', 'ş', 'Ü', 'ü'], ['c', 'c', 'g', 'g', 'i', 'i', 'i', 'o', 'o', 's', 's', 'u', 'u'])
            ->replace(['[', ']', '(', ')'], ' ')
            ->replace(['.', '-', '/'], '_')
            ->replaceMatches('/\s+/', '_')
            ->snake()
            ->upper()
            ->toString();
    }
}
