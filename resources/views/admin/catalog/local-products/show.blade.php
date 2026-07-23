@extends('layouts.prodelya-admin')

@section('title', $selectedVariant ? 'Varyant Detayı' : 'Ürün Detayı')
@section('page_title', $selectedVariant ? 'Varyant Detayı' : 'Ürün Detayı')
@section('page_subtitle', 'Kendi ürün detayında canonical alanlar, kompakt galeri ve sticky özet tek yüzeyde gösterilir.')

@section('content')
@php
    /** @var \App\Services\TenantCatalog\LocalProductFieldCatalogService $fieldCatalog */
    $fieldCatalog = app(\App\Services\TenantCatalog\LocalProductFieldCatalogService::class);
    $selectedVariant = $selectedVariant ?? null;
    $galleryItems = collect();

    $normalizeGalleryUrl = function (?string $url): ?string {
        if (!filled($url)) {
            return null;
        }

        $trimmed = trim((string) $url);
        $scheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($trimmed, PHP_URL_HOST));
        $path = (string) (parse_url($trimmed, PHP_URL_PATH) ?: '');

        if ($path !== '' && in_array($host, ['localhost', '127.0.0.1'], true) && str_starts_with($path, '/storage/')) {
            return $path;
        }

        if ($scheme === '' && str_starts_with($trimmed, '/storage/')) {
            return $trimmed;
        }

        return $trimmed;
    };

    $pushGallery = function (?string $url, string $label) use (&$galleryItems, $normalizeGalleryUrl) {
        $displayUrl = $normalizeGalleryUrl($url);
        if (!filled($displayUrl)) {
            return;
        }

        $key = strtolower(rtrim((string) (parse_url($displayUrl, PHP_URL_PATH) ?: $displayUrl), '/'));
        if ($key === '') {
            return;
        }

        $galleryItems->push([
            'url' => $displayUrl,
            'key' => $key,
            'label' => $label,
        ]);
    };

    if ($selectedVariant) {
        $pushGallery($selectedVariant->image_url ?: $product->image_url, $selectedVariant->display_name);
        foreach ($selectedVariant->images as $image) {
            $pushGallery($image->image_url, $selectedVariant->display_name);
        }
    }

    $pushGallery($product->getPrimaryImage(), $product->display_name);
    $pushGallery($product->image_url, $product->display_name);
    foreach ((array) data_get($product->tenant_attributes, 'catalog_images', []) as $imageUrl) {
        $pushGallery($imageUrl, $product->display_name);
    }
    foreach ($product->images as $image) {
        $pushGallery($image->image_url, $product->display_name);
    }

    $galleryItems = $galleryItems->unique('key')->values();
    $mainImage = $galleryItems->first();
    $variants = $product->variants()->orderBy('variant_name')->get();
    $variantStocks = collect($product->localStocks ?? [])->groupBy('tenant_catalog_product_variant_id');

    $variantAttributes = (array) data_get($selectedVariant?->meta, 'variant_attributes', data_get($product->meta, 'variant_attributes', []));
    $detailTitle = $selectedVariant?->display_name ?? $product->display_name;
    $detailCode = $selectedVariant?->variant_code ?? $product->display_code;
    $detailPrice = $selectedVariant?->display_price ?? $product->display_price;
    $detailCurrencyCode = $selectedVariant?->currency ?? $product->currency ?? 'TRY';
    $detailCurrency = $detailCurrencyCode === 'TRY' ? 'TL' : $detailCurrencyCode;
    $detailLocalStock = $selectedVariant
        ? round((float) collect($variantStocks->get($selectedVariant->id, []))->sum('quantity_on_hand'), 4)
        : round((float) collect($product->localStocks ?? [])->whereNull('tenant_catalog_product_variant_id')->sum('quantity_on_hand'), 4);
    $detailVat = data_get($selectedVariant?->meta, 'price_snapshot.vat_rate', data_get($product->meta, 'price_snapshot.vat_rate', 20));
    $detailDescription = trim(preg_replace('/<br\s*\/?>/i', "\n", strip_tags((string) ($product->description ?? ''), '<br>')) ?? '');
    $detailUrl = $selectedVariant
        ? route('admin.catalog.variants.show', ['product' => $product->id, 'variant' => $selectedVariant->id])
        : route('admin.catalog.local-products.show', $product);
    $sourceLabel = 'Kendi Ürünüm';

    $detailRows = [
        ['label' => $fieldCatalog->label('urun_id'), 'value' => (string) ($selectedVariant?->id ?? $product->id)],
        ['label' => $fieldCatalog->label('product_code'), 'value' => $detailCode ?: '-'],
        ['label' => $fieldCatalog->label('product_name'), 'value' => $detailTitle ?: '-'],
        ['label' => $fieldCatalog->label('category'), 'value' => $product->category_display_name ?: '-'],
        ['label' => $fieldCatalog->label('initial_stock'), 'value' => number_format((float) $detailLocalStock, 0, ',', '.')],
        ['label' => $fieldCatalog->label('list_price'), 'value' => $detailPrice !== null ? number_format((float) $detailPrice, 2, ',', '.') . ' ' . $detailCurrency : '-'],
        ['label' => $fieldCatalog->label('color'), 'value' => $selectedVariant?->variant_color ?: (data_get($variantAttributes, 'color') ?: '-')],
        ['label' => $fieldCatalog->label('measure'), 'value' => data_get($variantAttributes, 'measure', $selectedVariant?->variant_size) ?: '-'],
        ['label' => $fieldCatalog->label('dimensions'), 'value' => data_get($variantAttributes, 'dimensions') ?: '-'],
        ['label' => $fieldCatalog->label('vat_rate'), 'value' => $detailVat !== null ? rtrim(rtrim(number_format((float) $detailVat, 2, ',', '.'), '0'), ',') . '%' : '-'],
        ['label' => $fieldCatalog->label('product_url'), 'value' => $product->product_url ?: '-'],
        ['label' => $fieldCatalog->label('detail_url'), 'value' => $detailUrl],
    ];
@endphp
<style>
.pd-local-product-detail-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 265px;
    gap: 24px;
}
.pd-local-product-detail-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 330px;
    gap: 24px;
    align-items: start;
}
.pd-local-product-detail-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 18px;
}
.pd-local-product-detail-field {
    border: 1px solid #e8eef8;
    border-radius: 18px;
    background: #fbfdff;
    padding: 14px 16px;
}
.pd-local-product-detail-field span {
    display: block;
    font-size: 12px;
    color: #697892;
    margin-bottom: 6px;
}
.pd-local-product-detail-field strong,
.pd-local-product-detail-field a {
    font-size: 14px;
    color: #1f2b3d;
    word-break: break-word;
}
.pd-local-product-gallery-shell .pd-catalog-detail-main-image-wrap {
    min-height: 330px;
}
.pd-local-product-gallery-main {
    min-height: 330px;
    display: grid;
    place-items: center;
    border: 1px solid #e5ecf6;
    border-radius: 24px;
    background: linear-gradient(180deg, #f9fbff 0%, #f3f7ff 100%);
    overflow: hidden;
    padding: 12px;
}
.pd-local-product-gallery-main .pd-catalog-detail-main-image {
    max-width: 96%;
    max-height: 96%;
    object-fit: contain;
}
.pd-local-product-gallery-main .pd-catalog-detail-main-image[hidden] {
    display: none !important;
}
.pd-local-product-gallery-fallback,
.pd-local-product-gallery-thumb-fallback {
    display: grid;
    place-items: center;
    color: #6c7894;
    background: #eef4ff;
    border: 1px dashed #cfdcf6;
    text-align: center;
}
.pd-local-product-gallery-fallback {
    width: 100%;
    min-height: 306px;
    border-radius: 20px;
    font-size: 14px;
    line-height: 1.4;
}
.pd-local-product-gallery-fallback[hidden],
.pd-local-product-gallery-thumb-fallback[hidden] {
    display: none !important;
}
.pd-local-product-gallery-shell .pd-catalog-detail-thumb-grid {
    grid-template-columns: repeat(auto-fit, minmax(54px, 54px));
    gap: 10px;
}
.pd-local-product-gallery-thumb {
    width: 54px;
    height: 54px;
    padding: 0;
    display: grid;
    place-items: center;
    overflow: hidden;
    position: relative;
}
.pd-local-product-gallery-thumb img {
    width: 54px;
    height: 54px;
    object-fit: contain;
    background: #fff;
}
.pd-local-product-gallery-thumb img[hidden] {
    display: none !important;
}
.pd-local-product-gallery-thumb-fallback {
    width: 54px;
    height: 54px;
    border-radius: 16px;
    font-size: 11px;
    line-height: 1.2;
    padding: 4px;
}
.pd-local-product-gallery-thumb.is-broken {
    opacity: 0.6;
}
.pd-local-product-detail-description {
    margin-top: 20px;
    border: 1px solid #e8eef8;
    border-radius: 20px;
    background: #fbfdff;
    padding: 16px 18px;
    color: #23324a;
    line-height: 1.6;
}
.pd-local-product-detail-sticky .pd-catalog-detail-sticky-card {
    position: sticky;
    top: 24px;
}
@media (max-width: 1100px) {
    .pd-local-product-detail-layout {
        grid-template-columns: minmax(0, 1fr);
    }

    .pd-local-product-detail-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .pd-local-product-detail-fields {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
<div class="pd-local-product-shell">
    @include('admin.catalog.partials._local-products-subnav')

    <section class="pd-hero-card pd-catalog-detail-hero">
        <div class="pd-card-body">
            <div class="pd-catalog-detail-hero-row">
                <div>
                    <div class="pd-catalog-detail-breadcrumbs">
                        <a href="{{ route('admin.catalog.local-products') }}">Ürün Listem</a>
                        @if($selectedVariant)
                            <span>/</span>
                            <a href="{{ route('admin.catalog.local-products.show', $product) }}">{{ $product->display_name }}</a>
                        @endif
                        <span>/</span>
                        <span>{{ $selectedVariant ? 'Varyant Detayı' : 'Ürün Detayı' }}</span>
                    </div>
                    <h1 class="pd-catalog-detail-title">{{ $detailTitle }}</h1>
                    <div class="pd-catalog-detail-subtitle-row">
                        <span>{{ $detailCode }}</span>
                        <span>{{ $product->category_display_name ?: 'Kategori yok' }}</span>
                        <span>Kaynak: {{ $sourceLabel }}</span>
                    </div>
                    <div class="pd-catalog-detail-badges">
                        <span class="pd-badge pd-badge-purple">{{ $sourceLabel }}</span>
                        @if($selectedVariant?->variant_color)
                            <span class="pd-badge pd-badge-blue">{{ $selectedVariant->variant_color }}</span>
                        @endif
                        <span class="pd-badge pd-badge-{{ $selectedVariant ? ($selectedVariant->is_active ? 'green' : 'gray') : ($product->is_active ? 'green' : 'gray') }}">{{ $selectedVariant ? ($selectedVariant->is_active ? 'Aktif' : 'Pasif') : ($product->is_active ? 'Aktif' : 'Pasif') }}</span>
                        <span class="pd-badge pd-badge-{{ $selectedVariant ? ($selectedVariant->visible_in_catalog ? 'blue' : 'gray') : ($product->visible_in_catalog ? 'blue' : 'gray') }}">Katalog {{ $selectedVariant ? ($selectedVariant->visible_in_catalog ? 'Açık' : 'Kapalı') : ($product->visible_in_catalog ? 'Açık' : 'Kapalı') }}</span>
                    </div>
                </div>
                <div class="pd-catalog-detail-hero-actions">
                    <a href="{{ route('admin.catalog.local-products') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
                    <a href="{{ route('admin.catalog.local-products.edit', $product) }}" class="pd-btn pd-btn-primary">Ürünü Düzenle</a>
                </div>
            </div>
        </div>
    </section>

    <div class="pd-local-product-detail-layout">
        <div class="pd-catalog-detail-main">
            <section class="pd-section-card pd-catalog-detail-info-card">
                <div class="pd-section-header">
                    <div>
                        <h3 class="pd-section-title">Canonical Alanlar</h3>
                        <p class="pd-section-subtitle">Kendi ürün detayında kullanıcıya görünen minimum alan sözleşmesi tek yüzeyde toplanır.</p>
                    </div>
                </div>
                <div class="pd-section-body">
                    <div class="pd-local-product-detail-grid">
                        <div>
                            <div class="pd-local-product-detail-fields">
                                @foreach($detailRows as $row)
                                    <div class="pd-local-product-detail-field">
                                        <span>{{ $row['label'] }}</span>
                                        @if(in_array($row['label'], [$fieldCatalog->label('product_url'), $fieldCatalog->label('detail_url')], true) && $row['value'] !== '-')
                                            <a href="{{ $row['value'] }}" target="_blank" rel="noopener noreferrer">{{ $row['value'] }}</a>
                                        @else
                                            <strong>{{ $row['value'] }}</strong>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            @if($detailDescription !== '')
                                <div class="pd-local-product-detail-description">{!! nl2br(e($detailDescription)) !!}</div>
                            @endif
                        </div>
                        <div class="pd-catalog-detail-gallery-shell pd-local-product-gallery-shell" data-local-product-gallery>
                            <div class="pd-catalog-detail-main-image-wrap">
                                <div class="pd-local-product-gallery-main">
                                    @if($mainImage)
                                        <img src="{{ $mainImage['url'] }}" alt="{{ $detailTitle }}" class="pd-catalog-detail-main-image pd-allow-large" data-catalog-detail-main-image data-image-src="{{ $mainImage['url'] }}" data-image-label="{{ $detailTitle }}" hidden>
                                        <div class="pd-catalog-detail-main-image-empty pd-local-product-gallery-fallback" data-catalog-detail-image-fallback hidden>Görsel yüklenemedi.</div>
                                    @else
                                        <div class="pd-catalog-detail-main-image-empty pd-local-product-gallery-fallback">Bu ürün için görsel bulunmuyor.</div>
                                    @endif
                                </div>
                            </div>
                            @if($galleryItems->count() > 1)
                                <div class="pd-catalog-detail-thumb-grid">
                                    @foreach($galleryItems as $item)
                                        <button type="button" class="pd-catalog-detail-thumb pd-local-product-gallery-thumb {{ $loop->first ? 'is-active' : '' }}" data-catalog-detail-thumb data-image-src="{{ $item['url'] }}" data-image-label="{{ $item['label'] }}" aria-label="{{ $item['label'] ?: 'Ürün görseli' }}">
                                            <img src="{{ $item['url'] }}" alt="" class="pd-allow-large" data-gallery-thumb-image hidden>
                                            <span class="pd-local-product-gallery-thumb-fallback" data-gallery-thumb-fallback hidden>Görsel</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                            <div class="pd-catalog-detail-gallery-count">{{ $galleryItems->count() }} görsel</div>
                        </div>
                    </div>
                </div>
            </section>

            @if($variants->isNotEmpty())
                <section class="pd-section-card">
                    <div class="pd-section-header">
                        <div>
                            <h3 class="pd-section-title">Exact Varyantlar</h3>
                            <p class="pd-section-subtitle">Parent ortak metadata taşır; tüm operasyon truth’u exact varyant satırlarındadır.</p>
                        </div>
                    </div>
                    <div class="pd-section-body">
                        <div class="pd-local-product-table-wrap">
                            <table class="pd-table pd-local-product-table">
                                <thead>
                                    <tr>
                                        <th>Ürün / Varyant</th>
                                        <th>{{ $fieldCatalog->label('product_code') }}</th>
                                        <th>{{ $fieldCatalog->label('list_price') }}</th>
                                        <th>{{ $fieldCatalog->label('initial_stock') }}</th>
                                        <th>Aksiyon</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($variants as $variant)
                                        <tr>
                                            <td>
                                                <strong>{{ $variant->display_name }}</strong>
                                                <div class="pd-local-product-muted-row">{{ collect([$variant->variant_color, data_get($variant->meta, 'variant_attributes.measure', $variant->variant_size), data_get($variant->meta, 'variant_attributes.dimensions')])->filter()->implode(' / ') }}</div>
                                            </td>
                                            <td>{{ $variant->variant_code }}</td>
                                            <td>{{ $variant->display_price !== null ? number_format((float) $variant->display_price, 2, ',', '.') . ' ' . (($variant->currency ?? 'TRY') === 'TRY' ? 'TL' : ($variant->currency ?? 'TRY')) : '-' }}</td>
                                            <td>{{ number_format((float) collect($variantStocks->get($variant->id, []))->sum('quantity_on_hand'), 0, ',', '.') }}</td>
                                            <td>
                                                <div class="pd-local-product-action-row">
                                                    <a href="{{ route('admin.catalog.variants.show', ['product' => $product->id, 'variant' => $variant->id]) }}" class="pd-btn pd-btn-sm pd-btn-light">İncele</a>
                                                    <a href="{{ route('admin.stock-purchases.create', ['variant' => $variant->id]) }}" class="pd-btn pd-btn-sm pd-btn-light">Stoğa Al</a>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            @endif
        </div>

        <aside class="pd-catalog-detail-sidebar pd-local-product-detail-sticky">
            <section class="pd-section-card pd-catalog-detail-sticky-card">
                <div class="pd-section-header">
                    <div>
                        <h3 class="pd-section-title">Sticky Özet</h3>
                        <p class="pd-section-subtitle">Kaynak, fiyat, stok ve detay bağlantısı aynı panelde sabit tutulur.</p>
                    </div>
                </div>
                <div class="pd-section-body">
                    <div class="pd-local-product-sidebar-list">
                        <div class="pd-local-product-sidebar-row"><span>Kaynak</span><strong>{{ $sourceLabel }}</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>{{ $fieldCatalog->label('product_code') }}</span><strong>{{ $detailCode }}</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>{{ $fieldCatalog->label('initial_stock') }}</span><strong>{{ number_format((float) $detailLocalStock, 0, ',', '.') }}</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>{{ $fieldCatalog->label('list_price') }}</span><strong>{{ $detailPrice !== null ? number_format((float) $detailPrice, 2, ',', '.') . ' ' . $detailCurrency : '-' }}</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>Varyant Sayısı</span><strong>{{ $variants->count() }}</strong></div>
                        <div class="pd-local-product-sidebar-row"><span>{{ $fieldCatalog->label('detail_url') }}</span><strong>{{ $detailUrl }}</strong></div>
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
    const scope = document.querySelector('[data-local-product-gallery]');
    if (!scope) {
        return;
    }

    const mainImage = scope.querySelector('[data-catalog-detail-main-image]');
    const fallback = scope.querySelector('[data-catalog-detail-image-fallback]');
    const thumbs = Array.from(scope.querySelectorAll('[data-catalog-detail-thumb]'));

    const hideImage = (img) => {
        if (!img) {
            return;
        }
        img.setAttribute('hidden', 'hidden');
    };

    const showImage = (img) => {
        if (!img) {
            return;
        }
        img.removeAttribute('hidden');
    };

    const showMainFallback = (message = 'Görsel yüklenemedi.') => {
        hideImage(mainImage);
        if (mainImage) {
            mainImage.removeAttribute('src');
        }
        if (fallback) {
            fallback.textContent = message;
            fallback.removeAttribute('hidden');
        }
    };

    const hideMainFallback = () => {
        if (fallback) {
            fallback.setAttribute('hidden', 'hidden');
        }
    };

    const markThumbBroken = (thumb) => {
        const thumbImage = thumb.querySelector('[data-gallery-thumb-image]');
        const thumbFallback = thumb.querySelector('[data-gallery-thumb-fallback]');
        thumb.classList.add('is-broken');
        thumb.disabled = true;
        hideImage(thumbImage);
        if (thumbFallback) {
            thumbFallback.removeAttribute('hidden');
        }
    };

    const markThumbReady = (thumb) => {
        const thumbImage = thumb.querySelector('[data-gallery-thumb-image]');
        const thumbFallback = thumb.querySelector('[data-gallery-thumb-fallback]');
        thumb.classList.remove('is-broken');
        thumb.disabled = false;
        showImage(thumbImage);
        if (thumbFallback) {
            thumbFallback.setAttribute('hidden', 'hidden');
        }
    };

    const attachThumbState = (thumb) => {
        const thumbImage = thumb.querySelector('[data-gallery-thumb-image]');
        if (!thumbImage) {
            markThumbBroken(thumb);
            return;
        }

        const applyState = () => {
            if (thumbImage.complete && thumbImage.naturalWidth > 0) {
                markThumbReady(thumb);
            } else if (thumbImage.complete) {
                markThumbBroken(thumb);
            }
        };

        thumbImage.addEventListener('load', () => markThumbReady(thumb));
        thumbImage.addEventListener('error', () => markThumbBroken(thumb));
        applyState();
    };

    const loadMainImage = (src, label) => {
        if (!mainImage) {
            return;
        }
        if (!src) {
            showMainFallback('Bu ürün için görsel bulunmuyor.');
            return;
        }

        hideImage(mainImage);
        hideMainFallback();
        mainImage.alt = label || 'Ürün görseli';
        mainImage.src = src;

        const applyState = () => {
            if (mainImage.complete && mainImage.naturalWidth > 0) {
                showImage(mainImage);
                hideMainFallback();
            } else if (mainImage.complete) {
                showMainFallback();
            }
        };

        mainImage.onload = applyState;
        mainImage.onerror = () => showMainFallback();
        applyState();
    };

    thumbs.forEach((thumb) => {
        attachThumbState(thumb);
        thumb.addEventListener('click', () => {
            if (thumb.disabled) {
                return;
            }
            thumbs.forEach((node) => node.classList.remove('is-active'));
            thumb.classList.add('is-active');
            loadMainImage(thumb.getAttribute('data-image-src'), thumb.getAttribute('data-image-label'));
        });
    });

    if (mainImage) {
        loadMainImage(mainImage.getAttribute('data-image-src'), mainImage.getAttribute('data-image-label'));
    }
})();
</script>
@endsection
