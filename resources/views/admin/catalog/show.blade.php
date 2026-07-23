@extends('layouts.prodelya-admin')

@section('title', 'Ürün Detayı')
@section('page_title', 'Ürün Detayı')
@section('page_subtitle', 'Ürünün galeri, stok, görünürlük ve temel satış bilgilerini tek ekranda görüntüleyin.')

@section('content')
@php
    $sourceResolver = app(\App\Services\TenantCatalog\TenantCatalogProductSourceResolver::class);
    $resolvedSource = $sourceResolver->resolve($product->tenant_account_id, $product);
    $badgeLabel = $resolvedSource['badge_label'] ?? ($product->catalog_source_label ?? 'Ürün');
    $selectedVariant = $selectedVariant ?? null;

    $selectedVariantStockRows = collect($product->localStocks ?? [])->filter(function ($row) use ($product, $selectedVariant) {
        if (! $selectedVariant) {
            return false;
        }

        return (int) $row->tenant_account_id === (int) $product->tenant_account_id
            && (int) $row->tenant_catalog_product_id === (int) $product->id
            && (int) $row->tenant_catalog_product_variant_id === (int) $selectedVariant->id;
    });

    $detailTitle = $selectedVariant?->display_name ?? $product->display_name;
    $detailCode = $selectedVariant?->variant_code ?? $product->display_code;
    $detailCategory = $product->category_display_name ?: 'Kategori yok';
    $detailPrice = $selectedVariant?->display_price ?? $product->display_price;
    $detailCurrency = ($selectedVariant?->currency ?? $product->currency ?? 'TL') === 'TRY'
        ? 'TL'
        : ($selectedVariant?->currency ?? $product->currency ?? 'TL');
    $detailLocalStock = $selectedVariant
        ? round((float) $selectedVariantStockRows->sum(fn ($row) => (float) $row->quantity_on_hand), 4)
        : (float) ($product->local_stock_quantity ?? 0);
    $detailReserved = $selectedVariant
        ? round((float) $selectedVariantStockRows->sum(fn ($row) => (float) $row->quantity_reserved), 4)
        : null;
    $detailAvailable = $selectedVariant
        ? round((float) $selectedVariantStockRows->sum(fn ($row) => (float) $row->quantity_available), 4)
        : null;
    $detailSupplierStock = $selectedVariant?->supplier_stock_quantity ?? $product->supplier_stock_quantity;

    $normalizeGalleryKey = static function (?string $url): ?string {
        if (!filled($url)) {
            return null;
        }
        $trimmed = trim((string) $url);
        $path = parse_url($trimmed, PHP_URL_PATH) ?: $trimmed;
        $normalized = rtrim(strtolower($path), '/');
        return $normalized !== '' ? $normalized : strtolower($trimmed);
    };

    $galleryItems = collect();
    $pushGalleryItem = static function (&$galleryItems, ?string $url, string $origin, ?string $label = null) use ($normalizeGalleryKey) {
        if (!filled($url)) {
            return;
        }
        $galleryItems->push([
            'url' => trim((string) $url),
            'key' => $normalizeGalleryKey($url),
            'origin' => $origin,
            'label' => $label,
        ]);
    };

    if ($selectedVariant) {
        $pushGalleryItem($galleryItems, $selectedVariant->image_url, 'variant_primary', $selectedVariant->display_name);
        foreach ($selectedVariant->images as $image) {
            $pushGalleryItem($galleryItems, $image->image_url, 'variant_gallery', $selectedVariant->display_name);
        }
    }

    $pushGalleryItem($galleryItems, $product->getPrimaryImage(), 'product_primary', $product->display_name);
    $pushGalleryItem($galleryItems, $product->image_url, 'product_image_url', $product->display_name);

    foreach ((array) data_get($product->tenant_attributes, 'catalog_images', []) as $imageUrl) {
        $pushGalleryItem($galleryItems, $imageUrl, 'tenant_attribute', $product->display_name);
    }

    foreach ($product->images as $image) {
        $pushGalleryItem($galleryItems, $image->image_url, 'product_gallery', $product->display_name);
    }

    $variantRows = collect($product->variants)
        ->map(function ($variant) use ($pushGalleryItem, &$galleryItems, $product) {
            if (! $variant->relationLoaded('images')) {
                $variant->load('images');
            }

            if (! $variant->images->isEmpty()) {
                foreach ($variant->images as $image) {
                    $pushGalleryItem($galleryItems, $image->image_url, 'variant_gallery', $variant->display_name);
                }
            } else {
                $pushGalleryItem($galleryItems, $variant->image_url, 'variant_primary', $variant->display_name);
            }

            return [
                'id' => $variant->id,
                'name' => $variant->display_name,
                'code' => $variant->variant_code ?? '-',
                'color' => $variant->variant_color ?: 'Varyant',
                'image_url' => $variant->image_url,
                'local_stock' => (float) ($variant->local_stock_quantity ?? 0),
                'supplier_stock' => (float) ($variant->supplier_stock_quantity ?? 0),
                'display_price' => $variant->display_price,
                'currency' => ($variant->currency ?? 'TL') === 'TRY' ? 'TL' : ($variant->currency ?? 'TL'),
                'visible_in_catalog' => (bool) $variant->visible_in_catalog,
                'is_active' => (bool) $variant->is_active,
                'detail_url' => route('admin.catalog.variants.show', ['product' => $product->id, 'variant' => $variant->id]),
            ];
        })
        ->values();

    $galleryItems = $galleryItems
        ->filter(fn ($item) => filled($item['url']) && filled($item['key']))
        ->unique('key')
        ->values();

    $primaryGalleryItem = $galleryItems->first();
    $isLocalContext = $resolvedSource['source_type'] === \App\Services\TenantCatalog\TenantCatalogProductSourceResolver::OWN_PRODUCT;
@endphp
<div class="pd-local-product-shell">
    @if($isLocalContext)
        @include('admin.catalog.partials._local-products-subnav')
    @endif

    <section class="pd-hero-card pd-catalog-detail-hero">
        <div class="pd-card-body">
            <div class="pd-catalog-detail-hero-row">
                <div>
                    <div class="pd-catalog-detail-breadcrumbs">
                        <a href="{{ $isLocalContext ? route('admin.catalog.local-products') : route('admin.catalog.index') }}">{{ $isLocalContext ? 'Ürün Listem' : 'Katalog Ürünleri' }}</a>
                        @if($selectedVariant)
                            <span>/</span>
                            <a href="{{ route('admin.catalog.show', $product) }}">{{ $product->display_name }}</a>
                        @endif
                        <span>/</span>
                        <span>{{ $selectedVariant ? 'Varyant Detayı' : 'Ürün Detayı' }}</span>
                    </div>
                    <h1 class="pd-catalog-detail-title">{{ $detailTitle }}</h1>
                    <div class="pd-catalog-detail-subtitle-row">
                        <span>{{ $detailCode }}</span>
                        <span>{{ $detailCategory }}</span>
                        @if($selectedVariant)
                            <span>Ürün ailesi: {{ $product->display_name }}</span>
                        @endif
                    </div>
                    <div class="pd-catalog-detail-badges">
                        <span class="pd-badge pd-badge-purple">{{ $badgeLabel }}</span>
                        @if($selectedVariant)
                            <span class="pd-badge pd-badge-blue">{{ $selectedVariant->variant_color ?: 'Varyant' }}</span>
                        @endif
                        <span class="pd-badge pd-badge-{{ $selectedVariant ? ($selectedVariant->is_active ? 'green' : 'gray') : ($product->is_active ? 'green' : 'gray') }}">{{ $selectedVariant ? ($selectedVariant->is_active ? 'Aktif' : 'Pasif') : ($product->is_active ? 'Aktif' : 'Pasif') }}</span>
                        <span class="pd-badge pd-badge-{{ $selectedVariant ? ($selectedVariant->visible_in_catalog ? 'blue' : 'gray') : ($product->visible_in_catalog ? 'blue' : 'gray') }}">Katalog {{ $selectedVariant ? ($selectedVariant->visible_in_catalog ? 'Açık' : 'Kapalı') : ($product->visible_in_catalog ? 'Açık' : 'Kapalı') }}</span>
                        <span class="pd-badge pd-badge-{{ $product->visible_in_quote ? 'green' : 'gray' }}">Teklif {{ $product->visible_in_quote ? 'Açık' : 'Kapalı' }}</span>
                    </div>
                </div>
                <div class="pd-catalog-detail-hero-actions">
                    <a href="{{ $selectedVariant ? route('admin.catalog.local-products.supplier-stock') : ($isLocalContext ? route('admin.catalog.local-products') : route('admin.catalog.index')) }}" class="pd-btn pd-btn-light">Listeye Dön</a>
                    @if($isLocalContext)
                        <a href="{{ route('admin.catalog.local-products.create', ['edit' => $product->id]) }}" class="pd-btn pd-btn-primary">Ürünü Düzenle</a>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <div class="pd-catalog-detail-layout">
        <div class="pd-catalog-detail-main">
            <section class="pd-section-card pd-catalog-detail-info-card">
                <div class="pd-section-header">
                    <div>
                        <h3 class="pd-section-title">{{ $selectedVariant ? 'Varyant Özeti' : 'Ürün Özeti' }}</h3>
                        <p class="pd-section-subtitle">Temel ürün bilgileri, fiyat ve stok alanları kompakt bir düzende sunulur.</p>
                    </div>
                </div>
                <div class="pd-section-body">
                    <div class="pd-catalog-detail-info-grid">
                        <div class="pd-catalog-detail-metrics">
                            <div class="pd-catalog-detail-row"><span>{{ $selectedVariant ? 'Varyant Adı' : 'Ürün Adı' }}</span><strong>{{ $detailTitle }}</strong></div>
                            <div class="pd-catalog-detail-row"><span>{{ $selectedVariant ? 'Varyant Kodu / SKU' : 'Ürün Kodu / SKU' }}</span><strong>{{ $detailCode }}</strong></div>
                            <div class="pd-catalog-detail-row"><span>Kategori</span><strong>{{ $detailCategory ?: '-' }}</strong></div>
                            <div class="pd-catalog-detail-row"><span>Ürün Ailesi</span><strong>{{ $product->product_family ?: '-' }}</strong></div>
                            <div class="pd-catalog-detail-row"><span>Görünen satış fiyatı</span><strong>{{ $detailPrice ? number_format((float) $detailPrice, 2, ',', '.') . ' ' . $detailCurrency : '-' }}</strong></div>
                            <div class="pd-catalog-detail-row"><span>Local stok</span><strong>{{ number_format((float) $detailLocalStock, 0, ',', '.') }}</strong></div>
                            @if($selectedVariant)
                                <div class="pd-catalog-detail-row"><span>Rezerve</span><strong>{{ number_format((float) $detailReserved, 0, ',', '.') }}</strong></div>
                                <div class="pd-catalog-detail-row"><span>Kullanılabilir</span><strong>{{ number_format((float) $detailAvailable, 0, ',', '.') }}</strong></div>
                            @endif
                            <div class="pd-catalog-detail-row"><span>Tedarikçi stok</span><strong>{{ number_format((float) ($detailSupplierStock ?? 0), 0, ',', '.') }}</strong></div>
                            <div class="pd-catalog-detail-row"><span>Teklifte kullanım</span><strong>{{ $product->visible_in_quote ? 'Açık' : 'Kapalı' }}</strong></div>
                        </div>

                        <div class="pd-catalog-detail-gallery-shell">
                            <div class="pd-catalog-detail-main-image-wrap">
                                @if($primaryGalleryItem)
                                    <img src="{{ $primaryGalleryItem['url'] }}" alt="{{ $primaryGalleryItem['label'] ?: $detailTitle }}" class="pd-catalog-detail-main-image pd-allow-large" data-catalog-detail-main-image data-catalog-detail-image>
                                    <div class="pd-catalog-detail-main-image-empty" data-catalog-detail-image-fallback hidden style="display:none;">Görsel yüklenemedi.</div>
                                @else
                                    <div class="pd-catalog-detail-main-image-empty">Bu ürün için görsel bulunmuyor.</div>
                                @endif
                            </div>
                            @if($galleryItems->isNotEmpty())
                                <div class="pd-catalog-detail-thumb-grid">
                                    @foreach($galleryItems as $item)
                                        <button type="button" class="pd-catalog-detail-thumb {{ $loop->first ? 'is-active' : '' }}" data-catalog-detail-thumb data-image-src="{{ $item['url'] }}" aria-label="{{ $item['label'] ?: 'Ürün görseli' }}">
                                            <img src="{{ $item['url'] }}" alt="{{ $item['label'] ?: 'Ürün görseli' }}" class="pd-allow-large">
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                            <div class="pd-catalog-detail-gallery-count">{{ $galleryItems->count() }} görsel</div>
                        </div>
                    </div>

                    @if(filled($product->description))
                        <div class="pd-catalog-detail-description">{{ $product->description }}</div>
                    @endif
                </div>
            </section>

            @if($variantRows->isNotEmpty())
                <section class="pd-section-card">
                    <div class="pd-section-header">
                        <div>
                            <h3 class="pd-section-title">Varyasyonlar</h3>
                            <p class="pd-section-subtitle">Varyantlar, kendi görsel ve stok bilgileriyle aynı tabloda listelenir.</p>
                        </div>
                    </div>
                    <div class="pd-section-body">
                        <div class="pd-local-product-table-wrap">
                            <table class="pd-table pd-local-product-table">
                                <thead>
                                    <tr>
                                        <th>Renk / Özellik</th>
                                        <th>SKU</th>
                                        <th>Görsel</th>
                                        <th>Local Stok</th>
                                        <th>Tedarikçi Stok</th>
                                        <th>Fiyat</th>
                                        <th>Katalog</th>
                                        <th>Durum</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($variantRows as $variant)
                                        <tr>
                                            <td>
                                                <a href="{{ $variant['detail_url'] }}" class="pd-catalog-detail-variant-trigger">{{ $variant['name'] }}</a>
                                                <div class="pd-local-product-muted-row">{{ $variant['color'] }}</div>
                                            </td>
                                            <td>{{ $variant['code'] }}</td>
                                            <td>
                                                @if($variant['image_url'])
                                                    <img src="{{ $variant['image_url'] }}" alt="{{ $variant['name'] }}" class="pd-catalog-detail-variant-image pd-allow-large">
                                                @else
                                                    -
                                                @endif
                                            </td>
                                            <td>{{ number_format($variant['local_stock'], 0, ',', '.') }}</td>
                                            <td>{{ number_format($variant['supplier_stock'], 0, ',', '.') }}</td>
                                            <td>{{ $variant['display_price'] !== null ? number_format((float) $variant['display_price'], 2, ',', '.') . ' ' . $variant['currency'] : '-' }}</td>
                                            <td><span class="pd-badge pd-badge-{{ $variant['visible_in_catalog'] ? 'green' : 'gray' }}">{{ $variant['visible_in_catalog'] ? 'Açık' : 'Kapalı' }}</span></td>
                                            <td><span class="pd-badge pd-badge-{{ $variant['is_active'] ? 'green' : 'gray' }}">{{ $variant['is_active'] ? 'Aktif' : 'Pasif' }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            @endif

            <section class="pd-section-card">
                <div class="pd-section-header">
                    <div>
                        <h3 class="pd-section-title">Uyarılar</h3>
                        <p class="pd-section-subtitle">Yalnız kullanıcıya yönelik uyarılar bu alanda gösterilir.</p>
                    </div>
                </div>
                <div class="pd-section-body">
                    @if(!empty($product->warning_items))
                        <div class="pd-catalog-detail-warning-list">
                            @foreach($product->warning_items as $warning)
                                <span class="pd-badge pd-badge-amber">{{ $warning }}</span>
                            @endforeach
                        </div>
                    @else
                        <div class="pd-note">Bu ürün için aktif uyarı bulunmuyor.</div>
                    @endif
                </div>
            </section>
        </div>

        <aside class="pd-catalog-detail-sidebar">
            <section class="pd-section-card pd-catalog-detail-sticky-card">
                <div class="pd-section-header">
                    <div>
                        <h3 class="pd-section-title">Sticky Özet</h3>
                        <p class="pd-section-subtitle">Kaynak, varyasyon ve stok görünümü tek panelde özetlenir.</p>
                    </div>
                </div>
                <div class="pd-section-body">
                    <div class="pd-local-product-sidebar-list">
                        <div class="pd-local-product-sidebar-row"><span>Kaynak</span><strong>{{ $badgeLabel }}</strong></div>
                        @if($selectedVariant)
                            <div class="pd-local-product-sidebar-row"><span>Varyant</span><strong>{{ $selectedVariant->variant_color ?: 'Varyant' }}</strong></div>
                            <div class="pd-local-product-sidebar-row"><span>Ürün ailesi</span><strong>{{ $product->display_name }}</strong></div>
                        @endif
                        <div class="pd-local-product-sidebar-row"><span>Katalog durumu</span><strong>{{ $selectedVariant ? ($selectedVariant->visible_in_catalog ? 'Görünür' : 'Gizli') : ($product->visible_in_catalog ? 'Görünür' : 'Gizli') }}</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>Varyasyon</span><strong>{{ $variantRows->count() }}</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>Local stok</span><strong>{{ number_format((float) $detailLocalStock, 0, ',', '.') }}</strong></div>
                        @if($selectedVariant)
                            <div class="pd-local-product-sidebar-row"><span>Rezerve</span><strong>{{ number_format((float) $detailReserved, 0, ',', '.') }}</strong></div>
                            <div class="pd-local-product-sidebar-row"><span>Kullanılabilir</span><strong>{{ number_format((float) $detailAvailable, 0, ',', '.') }}</strong></div>
                        @endif
                        <div class="pd-local-product-sidebar-row"><span>Tedarikçi stok</span><strong>{{ number_format((float) ($detailSupplierStock ?? 0), 0, ',', '.') }}</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>Teklif kullanımı</span><strong>{{ $product->visible_in_quote ? 'İzinli' : 'Kapalı' }}</strong></div>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</div>
@endsection

@section('scripts')
@parent
<script>
(function () {
    const mainImage = document.querySelector('[data-catalog-detail-main-image]');
    const fallback = document.querySelector('[data-catalog-detail-image-fallback]');
    const thumbs = document.querySelectorAll('[data-catalog-detail-thumb]');
    if (!mainImage) {
        return;
    }

    const showFallback = () => {
        mainImage.setAttribute('hidden', 'hidden');
        if (fallback) {
            fallback.removeAttribute('hidden');
        }
    };

    const showImage = (src) => {
        if (!src) {
            showFallback();
            return;
        }

        mainImage.removeAttribute('hidden');
        if (fallback) {
            fallback.setAttribute('hidden', 'hidden');
        }
        mainImage.src = src;
    };

    mainImage.addEventListener('error', showFallback);
    mainImage.addEventListener('load', () => {
        mainImage.removeAttribute('hidden');
        if (fallback) {
            fallback.setAttribute('hidden', 'hidden');
        }
    });

    thumbs.forEach((thumb) => {
        thumb.addEventListener('click', () => {
            const src = thumb.getAttribute('data-image-src');
            if (!src) {
                return;
            }

            showImage(src);
            thumbs.forEach((node) => node.classList.remove('is-active'));
            thumb.classList.add('is-active');
        });
    });
})();
</script>
@endsection
