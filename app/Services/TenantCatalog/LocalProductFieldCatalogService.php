<?php

namespace App\Services\TenantCatalog;

class LocalProductFieldCatalogService
{
    public function all(): array
    {
        return [
            'product_type' => $this->makeField('product_type', 'Ürün Tipi', ['product_type', 'urun_turu'], [
                'request_key' => 'product_type',
                'supported' => true,
                'surfaces' => ['form', 'import'],
            ]),
            'group_code' => $this->makeField('group_code', 'Grup Kodu', ['group_code'], [
                'request_key' => 'group_code',
                'supported' => true,
                'surfaces' => ['form', 'import'],
            ]),
            'urun_id' => $this->makeField('urun_id', 'Ürün ID', ['urun_id'], [
                'request_key' => null,
                'supported' => false,
                'system_generated' => true,
                'surfaces' => ['detail'],
            ]),
            'product_code' => $this->makeField('product_code', 'Ürün Kodu / SKU', ['urun_kodu', 'product_code', 'variant_sku'], [
                'request_key' => 'product_code',
                'supported' => true,
                'required' => true,
                'surfaces' => ['form', 'import', 'detail'],
            ]),
            'product_name' => $this->makeField('product_name', 'Ürün Adı', ['urun_adi', 'product_name', 'variant_name'], [
                'request_key' => 'product_name',
                'supported' => true,
                'required' => true,
                'surfaces' => ['form', 'import', 'detail'],
            ]),
            'image_url' => $this->makeField('image_url', 'Ürün Görseli / Galeri', ['urun_resim', 'gorsel_url', 'image_url'], [
                'request_key' => 'image_url',
                'supported' => true,
                'surfaces' => ['form', 'import', 'detail'],
            ]),
            'product_url' => $this->makeField('product_url', 'Ürün URL', ['urun_url', 'product_url'], [
                'request_key' => 'product_url',
                'supported' => true,
                'surfaces' => ['form', 'import', 'detail'],
            ]),
            'category' => $this->makeField('category', 'Ürün Kategorisi', ['urun_kategori', 'kategori', 'category'], [
                'request_key' => 'standard_category_id',
                'supported' => true,
                'surfaces' => ['form', 'import', 'detail'],
            ]),
            'initial_stock' => $this->makeField('initial_stock', 'Ürün Stok', ['urun_stok', 'stok', 'baslangic_stogu', 'stock', 'initial_stock'], [
                'request_key' => 'local_stock_quantity',
                'supported' => true,
                'surfaces' => ['form', 'import', 'detail'],
            ]),
            'list_price' => $this->makeField('list_price', 'Ürün Fiyatı', ['urun_fiyat', 'liste_fiyati', 'display_price', 'list_price'], [
                'request_key' => 'display_price',
                'supported' => true,
                'surfaces' => ['form', 'import', 'detail'],
            ]),
            'color' => $this->makeField('color', 'Ürün Renk', ['urun_renk', 'color'], [
                'request_key' => 'variant_color',
                'supported' => true,
                'surfaces' => ['form', 'import', 'detail'],
            ]),
            'description' => $this->makeField('description', 'Ürün Açıklama', ['urun_aciklama', 'aciklama', 'description'], [
                'request_key' => 'description',
                'supported' => true,
                'surfaces' => ['form', 'import', 'detail'],
            ]),
            'vat_rate' => $this->makeField('vat_rate', 'Ürün KDV', ['urun_kdv', 'kdv_orani', 'kdv_var', 'vat_rate'], [
                'request_key' => 'vat_rate',
                'supported' => true,
                'surfaces' => ['form', 'import', 'detail'],
            ]),
            'detail_url' => $this->makeField('detail_url', 'Ürün Detay URL', ['urun_detay_url'], [
                'request_key' => null,
                'supported' => false,
                'system_generated' => true,
                'surfaces' => ['detail'],
            ]),
            'supplier_name' => $this->makeField('supplier_name', 'Ürün Tedarikçi', ['urun_tedarikci'], [
                'request_key' => null,
                'supported' => false,
                'supplier_only' => true,
                'surfaces' => ['detail'],
            ]),
            'measure' => $this->makeField('measure', 'Ürün Ölçü', ['urun_olcu', 'size'], [
                'request_key' => 'variant_size',
                'supported' => true,
                'surfaces' => ['form', 'import', 'detail'],
            ]),
            'dimensions' => $this->makeField('dimensions', 'Ürün Ebat', ['urun_ebat', 'dimensions'], [
                'request_key' => 'variant_dimensions',
                'supported' => true,
                'surfaces' => ['form', 'import', 'detail'],
            ]),
            'currency' => $this->makeField('currency', 'Para Birimi', ['para_birimi', 'currency'], [
                'request_key' => 'currency',
                'supported' => true,
                'surfaces' => ['form', 'import'],
            ]),
            'catalog_visible' => $this->makeField('catalog_visible', 'Katalogda Görünsün', ['katalogda_gorunsun', 'catalog_visible'], [
                'request_key' => 'visible_in_catalog',
                'supported' => true,
                'surfaces' => ['form', 'import'],
            ]),
            'quote_visible' => $this->makeField('quote_visible', 'Teklifte Kullanılsın', ['teklifte_kullanilsin', 'quote_visible'], [
                'request_key' => 'visible_in_quote',
                'supported' => true,
                'surfaces' => ['form', 'import'],
            ]),
            'status' => $this->makeField('status', 'Durum', ['aktif', 'status', 'active'], [
                'request_key' => 'is_active',
                'supported' => true,
                'surfaces' => ['form', 'import'],
            ]),
        ];
    }

    public function supported(): array
    {
        return array_filter($this->all(), static fn (array $field) => $field['supported'] === true);
    }

    public function field(string $canonicalKey): ?array
    {
        return $this->all()[$canonicalKey] ?? null;
    }

    public function label(string $canonicalKey, ?string $fallback = null): string
    {
        return $this->field($canonicalKey)['label'] ?? ($fallback ?? $canonicalKey);
    }

    public function labelsByCsvAlias(): array
    {
        $map = [];

        foreach ($this->all() as $field) {
            foreach ($field['csv_aliases'] as $alias) {
                $map[$alias] = $field['label'];
            }
        }

        return $map;
    }

    public function csvTemplateHeaders(): array
    {
        return [
            'product_type',
            'group_code',
            'urun_kodu',
            'urun_adi',
            'urun_kategori',
            'urun_fiyat',
            'para_birimi',
            'urun_stok',
            'urun_renk',
            'urun_olcu',
            'urun_ebat',
            'gorsel_url',
            'urun_url',
            'urun_kdv',
            'katalogda_gorunsun',
            'teklifte_kullanilsin',
            'aktif',
            'urun_aciklama',
        ];
    }

    public function importPreviewHeaders(): array
    {
        return [
            'line' => 'Satır',
            'product_type' => 'Ürün Tipi',
            'group_code' => 'Grup Kodu',
            'product_code' => 'Ürün Kodu / SKU',
            'product_name' => 'Ürün Adı',
            'category_label' => 'Ürün Kategorisi',
            'display_price' => 'Ürün Fiyatı',
            'currency' => 'Para Birimi',
            'initial_stock' => 'Ürün Stok',
            'color' => 'Ürün Renk',
            'measure' => 'Ürün Ölçü',
            'dimensions' => 'Ürün Ebat',
            'status' => 'Kontrol',
        ];
    }

    public function importHeaderLabel(string $header): string
    {
        return $this->labelsByCsvAlias()[$header] ?? $header;
    }

    public function detailKeys(): array
    {
        return array_keys(array_filter(
            $this->all(),
            static fn (array $field) => in_array('detail', $field['surfaces'] ?? [], true)
        ));
    }

    private function makeField(string $canonicalKey, string $label, array $aliases, array $overrides = []): array
    {
        return array_merge([
            'canonical_key' => $canonicalKey,
            'request_key' => $canonicalKey,
            'label' => $label,
            'csv_aliases' => $aliases,
            'supported' => false,
            'required' => false,
            'system_generated' => false,
            'supplier_only' => false,
            'surfaces' => [],
            'help' => null,
        ], $overrides);
    }
}
