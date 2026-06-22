@extends('layouts.prodelya-admin')

@section('title', 'Tedarikçi Profil Karşılaştırma')
@section('page_title', 'Tedarikçi Profil Karşılaştırma')
@section('page_subtitle', 'Yeni Nesil, Akdeniz, İlpen ve Etkin kaynaklarının aynı standart ürün diline dönüşümünü kontrol edin.')

@php
    $categoryRoute = Route::has('admin.super.product-data-hub.category-mappings.index')
        ? route('admin.super.product-data-hub.category-mappings.index')
        : '#';
    $fieldMappingRoute = Route::has('admin.super.product-data-hub.field-mappings.index')
        ? route('admin.super.product-data-hub.field-mappings.index')
        : '#';
    $sourcesRoute = Route::has('admin.super.product-data-hub.sources.index')
        ? route('admin.super.product-data-hub.sources.index')
        : '#';
    $rawProductsRoute = Route::has('admin.super.product-data-hub.raw-products.index')
        ? route('admin.super.product-data-hub.raw-products.index')
        : '#';
    $standardProductsRoute = Route::has('admin.super.product-data-hub.standard-products.index')
        ? route('admin.super.product-data-hub.standard-products.index')
        : '#';
    $pipelineRoute = Route::has('admin.super.product-data-hub.pipeline')
        ? route('admin.super.product-data-hub.pipeline')
        : '#';
    $catalogProjectionRoute = Route::has('admin.catalog.project')
        ? route('admin.catalog.project')
        : null;

    $profiles = collect($rows ?? [])->values();
    $profileCount = (int) data_get($summary ?? [], 'profile_count', $profiles->count() ?: 4);
    $readyCount = (int) data_get($summary ?? [], 'ready_count', $profiles->where('readiness', 'Hazır')->count());
    $categoryPendingCount = (int) data_get($summary ?? [], 'category_pending_count', $profiles->where('readiness', 'Kategori Eşleme Bekliyor')->count());
    $priceWarningCount = (int) data_get($summary ?? [], 'price_warning_count', $profiles->filter(fn ($row) => data_get($row, 'product.net_price_warning') || data_get($row, 'readiness') === 'Fiyat Kontrol Gerekli')->count());
    $missingCount = (int) data_get(
        $summary ?? [],
        'missing_count',
        max($profiles->count() - $readyCount, 0)
    );
    $galleryImageCount = $profiles->sum(function ($row) {
        return count(data_get($row, 'product.gallery_images', [])) + count(data_get($row, 'variant.gallery_images', []));
    });

    $statusLabels = [
        'YENI-NESIL' => 'Yeni Nesil',
        'AKDENIZ' => 'Akdeniz Promosyon',
        'ILPEN' => 'İlpen',
        'ETKIN' => 'Etkin Promosyon',
    ];

    $statusNotes = [
        'Hazır' => 'Standart dile dönüşüm yeterli görünüyor.',
        'Eksik Alan Var' => 'Kategori ve alan doluluğu tekrar gözden geçirilmeli.',
        'Kategori Eşleme Bekliyor' => 'Standart kategori eşlemesi tamamlanmadan projeksiyona geçilmemeli.',
        'Fiyat Kontrol Gerekli' => 'Fiyat alanları ticari kontrol bekliyor.',
        'Görsel Eksik' => 'Görsel veya galeri alanları eksik kalmış olabilir.',
        'Hata Var' => 'Kaynak veya normalize akışında hata bulundu.',
    ];

    $controlRequiredCount = $profiles->filter(fn ($row) => data_get($row, 'readiness') !== 'Hazır')->count();
@endphp

@section('page_actions')
@endsection

@section('content')
<div class="pd-hub-family-shell">
<section class="pd-hero-card">
    <div class="pd-card-body">
        <div class="pd-hero-main">
            <div class="pd-hero-copy">
                <h1 class="pd-hero-title">Tedarikçi Profil Karşılaştırma</h1>
                <p class="pd-hero-subtitle">Yeni Nesil, Akdeniz, İlpen ve Etkin kaynaklarının aynı standart ürün diline dönüşümünü kompakt profil kartlarıyla karşılaştırın.</p>
                <div class="pd-hero-badges">
                    <span class="pd-badge pd-badge-purple">Super Admin</span>
                    <span class="pd-badge pd-badge-blue">{{ $profileCount }} Profil</span>
                    <span class="pd-badge pd-badge-amber">{{ $controlRequiredCount }} Kontrol Gerekli</span>
                </div>
            </div>
            <div class="pd-hero-actions">
                <a href="{{ $categoryRoute }}" class="pd-btn pd-btn-primary {{ $categoryRoute === '#' ? 'pd-btn-disabled' : '' }}">Kategori Eşlemeye Geç</a>
                <a href="{{ $sourcesRoute }}" class="pd-btn pd-btn-light {{ $sourcesRoute === '#' ? 'pd-btn-disabled' : '' }}">Global Kaynaklar</a>
            </div>
        </div>
    </div>
</section>

<div class="pd-grid pd-grid-5 mb-6">
    <div class="pd-profile-metric">
        <div class="pd-stat-icon pd-profile-metric-icon pd-profile-metric-icon-blue">{{ $profileCount }}</div>
        <div>
            <div class="pd-profile-metric-label">Profil Sayısı</div>
            <div class="pd-profile-metric-value">{{ $profileCount }}</div>
        </div>
    </div>
    <div class="pd-profile-metric">
        <div class="pd-stat-icon pd-profile-metric-icon pd-profile-metric-icon-green">{{ $readyCount }}</div>
        <div>
            <div class="pd-profile-metric-label">Hazır Profil</div>
            <div class="pd-profile-metric-value">{{ $readyCount }}</div>
        </div>
    </div>
    <div class="pd-profile-metric">
        <div class="pd-stat-icon pd-profile-metric-icon pd-profile-metric-icon-amber">{{ $missingCount }}</div>
        <div>
            <div class="pd-profile-metric-label">Eksik / Kontrol</div>
            <div class="pd-profile-metric-value">{{ $missingCount }}</div>
        </div>
    </div>
    <div class="pd-profile-metric">
        <div class="pd-stat-icon pd-profile-metric-icon pd-profile-metric-icon-red">{{ $priceWarningCount }}</div>
        <div>
            <div class="pd-profile-metric-label">Fiyat Uyarısı</div>
            <div class="pd-profile-metric-value">{{ $priceWarningCount }}</div>
        </div>
    </div>
    <div class="pd-profile-metric">
        <div class="pd-stat-icon pd-profile-metric-icon pd-profile-metric-icon-purple">{{ $galleryImageCount }}</div>
        <div>
            <div class="pd-profile-metric-label">Galeri Görseli</div>
            <div class="pd-profile-metric-value">{{ $galleryImageCount }}</div>
        </div>
    </div>
</div>

<section class="pd-card">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Standart Veri Akışı</h3>
        <p class="pd-card-subtitle">Kategori eşleme ve tenant katalog projeksiyonu öncesi kontrol adımları.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-profile-flow">
            <div class="pd-profile-flow-step">
                <div class="pd-profile-flow-no">1</div>
                <strong>Global Kaynak</strong>
                <div class="pd-card-subtitle">XML / JSON / CSV / API</div>
            </div>
            <div class="pd-profile-flow-step">
                <div class="pd-profile-flow-no">2</div>
                <strong>Preview</strong>
                <div class="pd-card-subtitle">Gerçek kaynak / cache / dosya</div>
            </div>
            <div class="pd-profile-flow-step">
                <div class="pd-profile-flow-no">3</div>
                <strong>Alan Eşleme</strong>
                <div class="pd-card-subtitle">Ortak ürün dili</div>
            </div>
            <div class="pd-profile-flow-step">
                <div class="pd-profile-flow-no">4</div>
                <strong>Kategori Eşleme</strong>
                <div class="pd-card-subtitle">Standart kategori ağacı</div>
            </div>
            <div class="pd-profile-flow-step">
                <div class="pd-profile-flow-no">5</div>
                <strong>Tenant Katalog</strong>
                <div class="pd-card-subtitle">Satışa hazır görünüm</div>
            </div>
        </div>
    </div>
</section>

<section class="pd-profile-grid mb-6">
    @foreach($profiles as $row)
        @php
            $product = data_get($row, 'product', []);
            $variant = data_get($row, 'variant', []);
            $warnings = array_values(array_filter(data_get($row, 'warnings', [])));
            $errors = array_values(array_filter(data_get($row, 'errors', [])));
            $galleryImages = array_values(array_filter(data_get($product, 'gallery_images', [])));
            $primaryImage = data_get($product, 'image_url') ?: data_get($product, 'parent_image_url') ?: data_get($variant, 'variant_image_url');
            $galleryPreview = collect($galleryImages)->take(5);
            $extraGalleryCount = max(count($galleryImages) - $galleryPreview->count(), 0);
            $variantColor = data_get($variant, 'variant_color', '-');
            $variantSize = data_get($variant, 'variant_size', '-');
            $variantStock = data_get($variant, 'variant_stock_quantity', '-');
            $readiness = data_get($row, 'readiness', 'Kontrol Gerekli');
            $readinessBadge = data_get($row, 'readiness_badge', 'amber');
            $supplierLabel = $statusLabels[data_get($row, 'profile_key', '')] ?? data_get($row, 'supplier_name', 'Tedarikçi');
            $sourceModeLabel = data_get($row, 'source_mode') === 'live_source' ? 'Gerçek kaynak' : 'Demo fallback';
            $hasImage = !empty($primaryImage);
            $warningItems = collect(array_merge($warnings, $errors))->values();
            $visibleWarnings = $warningItems->take(2);
            $hiddenWarnings = $warningItems->slice(2)->values();
        @endphp
        <article class="pd-profile-card">
            <div class="pd-profile-head">
                <div>
                    <h3 class="pd-profile-name">{{ $supplierLabel }}</h3>
                    <div class="pd-profile-sub">Model: {{ data_get($row, 'model_type', '-') }}</div>
                    <div class="pd-profile-sub">{{ $sourceModeLabel }}</div>
                </div>
                <span class="pd-badge pd-badge-{{ $readinessBadge }}">{{ $readiness }}</span>
            </div>
            <div class="pd-profile-body">
                <div class="pd-profile-info-grid">
                    <div class="pd-profile-info">
                        <div class="pd-profile-info-label">Kod</div>
                        <div class="pd-profile-info-value">
                            Ürün: {{ data_get($product, 'generated_product_code', '-') }}<br>
                            Varyasyon: {{ data_get($variant, 'generated_variant_code', '-') }}<br>
                            <span class="pd-profile-note">Tedarikçi Kod: {{ data_get($product, 'supplier_product_code', '-') }}</span><br>
                            <span class="pd-profile-note">Grup Kod: {{ data_get($product, 'supplier_group_code', data_get($variant, 'supplier_group_code', 'Yok')) }}</span>
                        </div>
                    </div>
                    <div class="pd-profile-info">
                        <div class="pd-profile-info-label">Ürün</div>
                        <div class="pd-profile-info-value">
                            {{ data_get($product, 'product_name', data_get($product, 'display_product_name', '-')) }}<br>
                            <span class="pd-profile-note">{{ data_get($product, 'display_product_name', '-') }}</span>
                        </div>
                    </div>
                    <div class="pd-profile-info">
                        <div class="pd-profile-info-label">Kategori</div>
                        <div class="pd-profile-info-value">
                            {{ data_get($product, 'supplier_category_name', '-') }}<br>
                            @if(!empty(data_get($product, 'standard_category_id')))
                                <span class="pd-profile-note">Standart: {{ data_get($product, 'standard_category_id') }}</span>
                            @else
                                <span class="pd-badge pd-badge-amber">Eşleme bekliyor</span>
                            @endif
                        </div>
                    </div>
                    <div class="pd-profile-info">
                        <div class="pd-profile-info-label">Renk / Ebat</div>
                        <div class="pd-profile-info-value">
                            {{ $variantColor }} / {{ $variantSize }}<br>
                            @if(!empty(data_get($variant, 'extracted_color_source')))
                                <span class="pd-badge pd-badge-blue">Renk çıkarıldı</span>
                            @endif
                        </div>
                    </div>
                    <div class="pd-profile-info">
                        <div class="pd-profile-info-label">Fiyat</div>
                        <div class="pd-profile-info-value">
                            Alış/Net: {{ is_null(data_get($product, 'purchase_price')) ? '-' : number_format((float) data_get($product, 'purchase_price'), 2, ',', '.') }} {{ data_get($product, 'currency', '') }}<br>
                            Liste: {{ is_null(data_get($product, 'list_price')) ? '-' : number_format((float) data_get($product, 'list_price'), 2, ',', '.') }}<br>
                            Kapalı Liste: {{ is_null(data_get($product, 'closed_list_price')) ? '-' : number_format((float) data_get($product, 'closed_list_price'), 2, ',', '.') }}<br>
                            İskonto: {{ is_null(data_get($product, 'discount_rate')) ? '-' : number_format((float) data_get($product, 'discount_rate'), 2, ',', '.') }}
                        </div>
                        <div class="mt-2">
                            @if(!empty(data_get($product, 'net_price_warning')))
                                <span class="pd-badge pd-badge-red">Net Fiyat Uyarısı</span>
                            @endif
                            @if(!empty(data_get($product, 'warning_flag')))
                                <span class="pd-badge pd-badge-amber">Özel Uyarı</span>
                            @endif
                        </div>
                    </div>
                    <div class="pd-profile-info">
                        <div class="pd-profile-info-label">Stok</div>
                        <div class="pd-profile-info-value">
                            Ürün: {{ data_get($product, 'stock_quantity', data_get($product, 'total_variant_stock_quantity', '-')) }}<br>
                            Varyasyon: {{ $variantStock }}<br>
                            <span class="pd-profile-note">Toplam: {{ data_get($product, 'total_variant_stock_quantity', '-') }}</span>
                        </div>
                    </div>
                </div>

                <div class="pd-profile-media-row">
                    <div class="pd-media-thumb-wrap">
                        @if($hasImage)
                            <img src="{{ $primaryImage }}" alt="Ürün görseli" class="pd-product-thumb pd-allow-large" onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden');">
                            <span class="pd-badge pd-badge-gray pd-image-fallback hidden">Görsel Yok</span>
                        @else
                            <span class="pd-badge pd-badge-gray pd-image-fallback">Görsel Yok</span>
                        @endif
                    </div>
                    <div class="min-w-0">
                        <strong>Görsel / Galeri</strong>
                        <div class="pd-media-links">
                            @if(!empty($primaryImage))
                                <a href="{{ $primaryImage }}" target="_blank" rel="noopener noreferrer">Görseli Aç</a>
                            @endif
                            @if(!empty(data_get($product, 'product_url')))
                                <a href="{{ data_get($product, 'product_url') }}" target="_blank" rel="noopener noreferrer">Ürün Sayfası</a>
                            @elseif(!empty(data_get($product, 'detail_url')))
                                <a href="{{ data_get($product, 'detail_url') }}" target="_blank" rel="noopener noreferrer">Detay Linki</a>
                            @else
                                <span class="pd-badge pd-badge-gray">Ürün Sayfası Yok</span>
                            @endif
                            @if(!empty(data_get($product, 'detail_url')) && data_get($product, 'detail_url') !== data_get($product, 'product_url'))
                                <a href="{{ data_get($product, 'detail_url') }}" target="_blank" rel="noopener noreferrer">Detay Linki</a>
                            @endif
                            @if(!empty(data_get($product, 'artwork_template_url')))
                                <a href="{{ data_get($product, 'artwork_template_url') }}" target="_blank" rel="noopener noreferrer">Trase / Şablon</a>
                            @endif
                        </div>
                        <div class="mt-2">
                            <span class="pd-badge pd-badge-blue">Galeri: {{ count($galleryImages) }} görsel</span>
                            @if(!empty(data_get($variant, 'image_fallback_used')))
                                <span class="pd-badge pd-badge-amber">Fallback</span>
                            @endif
                        </div>
                        @if($galleryPreview->isNotEmpty())
                            <div class="pd-gallery-strip">
                                @foreach($galleryPreview as $galleryImage)
                                    <img src="{{ $galleryImage }}" alt="Galeri görseli" class="pd-allow-large" onerror="this.style.display='none';">
                                @endforeach
                                @if($extraGalleryCount > 0)
                                    <span class="pd-badge pd-badge-gray">+{{ $extraGalleryCount }} görsel</span>
                                @endif
                            </div>
                        @endif
                        <div class="pd-media-source-note">
                            Ana görsel alanı: {{ data_get($product, 'image_source_field', 'image_url') }}<br>
                            Galeri alanı: {{ !empty(data_get($product, 'gallery_source_fields')) ? implode(', ', (array) data_get($product, 'gallery_source_fields')) : 'gallery_images' }}
                        </div>
                    </div>
                </div>

                <div class="pd-profile-warnings">
                    @forelse($visibleWarnings as $warningItem)
                        <div class="pd-profile-warning {{ str_contains(mb_strtolower((string) $warningItem), 'hazır') || str_contains(mb_strtolower((string) $warningItem), 'korundu') || str_contains(mb_strtolower((string) $warningItem), 'çalışıyor') ? 'pd-profile-warning-ok' : '' }}">
                            {{ $warningItem }}
                        </div>
                    @empty
                        <div class="pd-profile-warning pd-profile-warning-ok">Kritik uyarı yok.</div>
                    @endforelse
                    @if($hiddenWarnings->isNotEmpty())
                        <details class="pd-warning-details">
                            <summary>Uyarıları İncele ({{ $warningItems->count() }})</summary>
                            <ul class="pd-warning-list">
                                @foreach($hiddenWarnings as $warningItem)
                                    <li>{{ $warningItem }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                    @if(!empty($statusNotes[$readiness]))
                        <div class="pd-profile-note-box">{{ $statusNotes[$readiness] }}</div>
                    @endif
                </div>
            </div>
        </article>
    @endforeach
</section>

<section class="pd-card pd-section-card mb-20">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Kategori Eşleme Öncesi Kontrol</h3>
        <p class="pd-card-subtitle">Bu tablo hangi tedarikçide hangi alanın kategori eşleme öncesi hazır olduğunu gösterir.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Tedarikçi</th>
                        <th>Kod</th>
                        <th>Ürün Adı</th>
                        <th>Kategori</th>
                        <th>Varyasyon</th>
                        <th>Görsel</th>
                        <th>Fiyat</th>
                        <th>Durum</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($profiles as $row)
                        @php
                            $product = data_get($row, 'product', []);
                            $variant = data_get($row, 'variant', []);
                            $hasCode = !empty(data_get($product, 'generated_product_code'));
                            $hasName = !empty(data_get($product, 'product_name')) || !empty(data_get($product, 'display_product_name'));
                            $hasCategory = !empty(data_get($product, 'supplier_category_name'));
                            $hasVariant = !empty(data_get($variant, 'generated_variant_code')) || !empty(data_get($variant, 'variant_stock_code'));
                            $hasImage = !empty(data_get($product, 'image_url')) || !empty(data_get($variant, 'variant_image_url'));
                            $hasPrice = !is_null(data_get($product, 'purchase_price'));
                            $readiness = data_get($row, 'readiness', 'Kontrol Gerekli');
                            $readinessBadge = data_get($row, 'readiness_badge', 'amber');
                            $modelType = data_get($row, 'model_type', '');
                        @endphp
                        <tr>
                            <td>{{ $statusLabels[data_get($row, 'profile_key', '')] ?? data_get($row, 'supplier_name', 'Tedarikçi') }}</td>
                            <td><span class="pd-badge pd-badge-{{ $hasCode ? 'green' : 'red' }}">{{ $hasCode ? 'Var' : 'Eksik' }}</span></td>
                            <td><span class="pd-badge pd-badge-{{ $hasName ? 'green' : 'amber' }}">{{ $hasName ? 'Var' : 'Kontrol' }}</span></td>
                            <td><span class="pd-badge pd-badge-{{ $hasCategory ? 'green' : 'amber' }}">{{ $hasCategory ? 'Var' : 'Eşleme' }}</span></td>
                            <td>
                                @if(str_contains($modelType, 'json'))
                                    <span class="pd-badge pd-badge-green">JSON</span>
                                @elseif(str_contains($modelType, 'nested'))
                                    <span class="pd-badge pd-badge-green">Nested</span>
                                @else
                                    <span class="pd-badge pd-badge-{{ $hasVariant ? 'green' : 'amber' }}">{{ $hasVariant ? 'Var' : 'Kontrol' }}</span>
                                @endif
                            </td>
                            <td>
                                @if(!empty(data_get($variant, 'image_fallback_used')))
                                    <span class="pd-badge pd-badge-amber">Fallback</span>
                                @elseif(count((array) data_get($product, 'gallery_images', [])) > 1)
                                    <span class="pd-badge pd-badge-green">Galeri</span>
                                @else
                                    <span class="pd-badge pd-badge-{{ $hasImage ? 'green' : 'red' }}">{{ $hasImage ? 'Var' : 'Eksik' }}</span>
                                @endif
                            </td>
                            <td>
                                @if(!empty(data_get($product, 'net_price_warning')))
                                    <span class="pd-badge pd-badge-red">Net Fiyat</span>
                                @else
                                    <span class="pd-badge pd-badge-{{ $hasPrice ? 'green' : 'amber' }}">{{ $hasPrice ? 'Var' : 'Kontrol' }}</span>
                                @endif
                            </td>
                            <td><span class="pd-badge pd-badge-{{ $readinessBadge }}">{{ $readiness }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="pd-note mt-3">
            Bir sonraki adımda tedarikçi kategori adları Prodelya Standart Kategori Ağacı’na bağlanacak. Tenant katalog projeksiyonu bu eşlemeden sonra daha temiz çalışır.
        </div>
    </div>
</section>
</div>

@endsection

@section('bottom_actions')
<div>
    <strong>Sonraki adım:</strong>
    <span class="pd-muted">Kategori eşlemeleri tamamlanıp Gelişmiş Ürün ve Katalog projeksiyonu güncellenecek.</span>
</div>
<div class="pd-bottom-action-buttons">
    <a href="{{ $sourcesRoute }}" class="pd-btn pd-btn-light {{ $sourcesRoute === '#' ? 'pd-btn-disabled' : '' }}">Global Kaynaklar</a>
    <a href="{{ $fieldMappingRoute }}" class="pd-btn pd-btn-light {{ $fieldMappingRoute === '#' ? 'pd-btn-disabled' : '' }}">Alan Eşleme</a>
    <a href="{{ $categoryRoute }}" class="pd-btn pd-btn-warning {{ $categoryRoute === '#' ? 'pd-btn-disabled' : '' }}">Kategori Eşleme</a>
    @if($catalogProjectionRoute)
        <a href="{{ $catalogProjectionRoute }}" class="pd-btn pd-btn-primary">Katalog Projeksiyonunu Güncelle</a>
    @else
        <span class="pd-btn pd-btn-primary pd-btn-disabled">Katalog Projeksiyonunu Güncelle</span>
    @endif
</div>
@endsection

@section('side_summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Profil Kontrol Özeti</h3>
        <div class="pd-card-subtitle mb-4">Kategori eşleme öncesi durum</div>

        <div class="pd-status-list">
            @foreach($profiles as $row)
                @php
                    $label = $statusLabels[data_get($row, 'profile_key', '')] ?? data_get($row, 'supplier_name', 'Tedarikçi');
                @endphp
                <div class="pd-status-row">
                    <span>{{ $label }}</span>
                    <span class="pd-badge pd-badge-{{ data_get($row, 'readiness_badge', 'amber') }}">{{ data_get($row, 'readiness', 'Kontrol Gerekli') }}</span>
                </div>
            @endforeach
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Hızlı Aksiyonlar</h4>
            <div class="pd-summary-action-list">
                <a href="{{ $categoryRoute }}" class="pd-summary-action"><span>Kategori Eşleme</span><span class="pd-badge pd-badge-amber">Kategori</span></a>
                <a href="{{ $fieldMappingRoute }}" class="pd-summary-action"><span>Alan Eşleme</span><span class="pd-badge pd-badge-blue">Alan</span></a>
                <a href="{{ $sourcesRoute }}" class="pd-summary-action"><span>Global Kaynaklar</span><span class="pd-badge pd-badge-green">Kaynak</span></a>
                <a href="{{ $rawProductsRoute }}" class="pd-summary-action"><span>Ham Ürünler</span><span class="pd-badge pd-badge-gray">Ham</span></a>
                <a href="{{ $standardProductsRoute }}" class="pd-summary-action"><span>Standart Ürünler</span><span class="pd-badge pd-badge-purple">Standart</span></a>
                <a href="{{ $pipelineRoute }}" class="pd-summary-action"><span>Akış Kontrol Paneli</span><span class="pd-badge pd-badge-blue">Akış</span></a>
            </div>
        </div>

        <div class="pd-side-note">Bu ekran kategori eşleme ve Gelişmiş Ürün ve Katalog sunumu öncesi kontrol amaçlıdır.</div>
    </div>
</div>
@endsection
