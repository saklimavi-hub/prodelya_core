@extends('layouts.prodelya-admin')

@section('title', 'Tedarikçi Ürün Önizleme')

@section('content')
@php
    $metricCards = [
        ['label' => 'Toplam tedarikçi ürünü', 'value' => $summary['total_supplier_products'], 'class' => 'pd-metric-card-soft-blue'],
        ['label' => 'Standart ürüne dönüşen', 'value' => $summary['standardized_products'], 'class' => 'pd-metric-card-soft-green'],
        ['label' => 'Tenant kataloğa çıkan', 'value' => $summary['tenant_catalog_products'], 'class' => 'pd-metric-card-soft-purple'],
        ['label' => 'Kategori eksik', 'value' => $summary['missing_category'], 'class' => 'pd-metric-card-soft-amber'],
        ['label' => 'Fiyat eksik', 'value' => $summary['missing_price'], 'class' => 'pd-metric-card-soft-amber'],
        ['label' => 'Uyarılı ürün', 'value' => $summary['warning_products'], 'class' => 'pd-metric-card-soft-red'],
    ];
@endphp

<div class="pd-hub-family-shell">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Tedarikçi Ürün Önizleme</h1>
                    <p class="pd-hero-subtitle">Tedarikçi ürününü preview, staging, standart ürün ve tenant katalog çıkışıyla birlikte görün. Gerekirse ürün bazlı kategori override verin.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">Preview</span>
                        <span class="pd-badge pd-badge-green">Standart Ürün</span>
                        <span class="pd-badge pd-badge-purple">Tenant Katalog</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Global Kaynaklar</a>
                    <a href="{{ route('admin.super.product-data-hub.standard-products.index') }}" class="pd-btn pd-btn-light">Standart Ürün Havuzu</a>
                    <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-primary">Tenant Katalog Çıkışı</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-metric-grid">
        @foreach($metricCards as $metric)
            <div class="pd-metric-card {{ $metric['class'] }}">
                <div class="pd-metric-card-label">{{ $metric['label'] }}</div>
                <div class="pd-metric-card-value">{{ $metric['value'] }}</div>
            </div>
        @endforeach
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">XML Toplama Hattı</h3>
                <p class="pd-section-subtitle">Kaynak, staging, kategori eşleme, standart ürün ve tenant katalog adımlarını aynı hat üzerinde izleyin.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-process-grid">
                @foreach($processSteps as $step)
                    <div class="pd-process-card">
                        <div class="pd-process-head">
                            <span class="pd-badge pd-badge-{{ $step['status'] }}">{{ $step['status_label'] }}</span>
                            <span class="pd-process-count">{{ $step['count'] }}</span>
                        </div>
                        <div class="pd-process-title">{{ $step['title'] }}</div>
                        <a href="{{ $step['action_route'] }}" class="pd-process-link">{{ $step['action_label'] }}</a>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="pd-section-card">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Tedarikçi Ürünleri</h3>
                <p class="pd-section-subtitle">Tedarikçi kodu, standart ürün kodu, kategori, stok, fiyat ve son sync değişimini listeleyin.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="GET" class="pd-form-grid-2 mb-4">
                <div>
                    <label class="pd-label">Kaynak</label>
                    <select name="source_id" class="pd-select">
                        <option value="">Tüm aktif kaynaklar</option>
                        @foreach($sources as $source)
                            <option value="{{ $source->id }}" {{ (int) $sourceId === $source->id ? 'selected' : '' }}>{{ $source->supplier->name }} / {{ $source->source_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pd-hero-actions pd-hero-actions-inline">
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.super.product-data-hub.supplier-products') }}" class="pd-btn pd-btn-light">Sıfırla</a>
                </div>
            </form>

            @if($products->isEmpty())
                <div class="pd-note">Henüz görünür tedarikçi ürünü yok. Önce kaynak preview/sync çalıştırın ve staging kaydını oluşturun.</div>
            @else
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead>
                            <tr>
                                <th>Görsel</th>
                                <th>Tedarikçi</th>
                                <th>Ürün adı</th>
                                <th>Tedarikçi kodu</th>
                                <th>Standart ürün kodu</th>
                                <th>Tedarikçi kategori</th>
                                <th>Standart kategori</th>
                                <th>Stok</th>
                                <th>Liste fiyatı</th>
                                <th>Uyarılar</th>
                                <th>Son sync değişimi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($products as $product)
                                @php
                                    $isSelected = $selectedProduct && $selectedProduct->id === $product->id;
                                    $standardProductCode = $product->standardProduct?->standard_product_code ?: '-';
                                    $standardCategory = $product->standardProduct?->category_display_name
                                        ?: data_get($product->normalized_payload, 'category_override_name')
                                        ?: '-';
                                    $stockValue = $product->stock_quantity
                                        ?? data_get($product->normalized_payload, 'stock_quantity')
                                        ?? data_get($product->normalized_payload, 'total_variant_stock_quantity')
                                        ?? data_get($product->normalized_payload, 'variant_stock_quantity');
                                    $listPrice = data_get($product->normalized_payload, 'list_price');
                                    $lastSyncStatus = data_get($product->normalized_payload, '_sync_meta.last_sync_status', $product->sync_status);
                                @endphp
                                <tr class="{{ $isSelected ? 'pd-table-row-selected' : '' }}">
                                    <td>
                                        @if($product->image_url)
                                            <a href="{{ route('admin.super.product-data-hub.supplier-products', ['source_id' => $sourceId, 'selected_product_id' => $product->id]) }}">
                                                <img src="{{ $product->image_url }}" alt="{{ $product->product_name }}" class="pd-product-thumb-sm">
                                            </a>
                                        @else
                                            <span class="pd-badge pd-badge-gray">Yok</span>
                                        @endif
                                    </td>
                                    <td>{{ $product->supplier?->name ?: '-' }}</td>
                                    <td><a href="{{ route('admin.super.product-data-hub.supplier-products', ['source_id' => $sourceId, 'selected_product_id' => $product->id]) }}">{{ $product->product_name ?: '-' }}</a></td>
                                    <td>{{ $product->supplier_product_code ?: '-' }}</td>
                                    <td>{{ $standardProductCode }}</td>
                                    <td>{{ $product->supplier_category_name ?: '-' }}</td>
                                    <td>{{ $standardCategory }}</td>
                                    <td>{{ is_null($stockValue) ? 'Stok bilgisi gelmedi' : number_format((float) $stockValue, 0, ',', '.') }}</td>
                                    <td>{{ is_null($listPrice) ? 'Fiyat eksik' : number_format((float) $listPrice, 2, ',', '.') . ' ' . ($product->currency ?: 'TL') }}</td>
                                    <td>
                                        <div class="pd-inline-badges">
                                            @php
                                                $supplierName = $product->supplier?->name ?: '';
                                            @endphp
                                            @if(data_get($product->normalized_payload, 'supplier_warning_flag'))
                                                @if(str_contains(mb_strtolower($supplierName), 'etkin'))
                                                    <span class="pd-badge pd-badge-red">Kırmızı Ürün</span>
                                                @elseif(str_contains(mb_strtolower($supplierName), 'yeni nesil'))
                                                    <span class="pd-badge pd-badge-amber">Turuncu Ürün</span>
                                                @endif
                                            @endif
                                            @if($product->warning_flag && !data_get($product->normalized_payload, 'supplier_warning_flag'))
                                                <span class="pd-badge pd-badge-amber">Veri kalite uyarısı</span>
                                            @endif
                                            @if(data_get($product->normalized_payload, 'net_price_warning'))
                                                <span class="pd-badge pd-badge-amber">Net fiyat uyarısı</span>
                                            @endif
                                            @if(data_get($product->normalized_payload, 'price_policy_warning'))
                                                <span class="pd-badge pd-badge-blue">Fiyat kontrolü gerekli</span>
                                            @endif
                                            @if(blank($product->standard_category_id) && blank(data_get($product->normalized_payload, 'category_override_standard_category_id')))
                                                <span class="pd-badge pd-badge-amber">Kategori eksik</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td>{{ $lastSyncStatus ?: '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    @if($selectedProduct)
        @php
            $selectedStandardProduct = $selectedProduct->standardProduct;
            $selectedTenantCatalogs = $selectedStandardProduct?->tenantCatalogProducts ?? collect();
        @endphp
        <section class="pd-section-card pd-section-card-soft-purple">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Ürün Detayı ve Kategori Override</h3>
                    <p class="pd-section-subtitle">Ham bilgi, normalize veri, snapshot’lar ve tenant katalog durumu.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-grid pd-grid-2">
                    <div class="pd-detail-box">
                        <div class="pd-detail-box-title">Ürün Özeti</div>
                        <div class="pd-grid pd-grid-2">
                            <div class="pd-profile-info">
                                <div class="pd-profile-info-label">Tedarikçi</div>
                                <div class="pd-profile-info-value">{{ $selectedProduct->supplier?->name ?: '-' }}</div>
                            </div>
                            <div class="pd-profile-info">
                                <div class="pd-profile-info-label">Tedarikçi kodu</div>
                                <div class="pd-profile-info-value">{{ $selectedProduct->supplier_product_code ?: '-' }}</div>
                            </div>
                            <div class="pd-profile-info">
                                <div class="pd-profile-info-label">Standart ürün kodu</div>
                                <div class="pd-profile-info-value">{{ $selectedStandardProduct?->standard_product_code ?: '-' }}</div>
                            </div>
                            <div class="pd-profile-info">
                                <div class="pd-profile-info-label">Tenant katalog durumu</div>
                                <div class="pd-profile-info-value">{{ $selectedTenantCatalogs->where('is_active', true)->where('visible_in_catalog', true)->count() > 0 ? 'Tenant kataloğa çıktı' : 'Henüz tenant katalogda değil' }}</div>
                            </div>
                            <div class="pd-profile-info">
                                <div class="pd-profile-info-label">Stok snapshot</div>
                                <div class="pd-profile-info-value">{{ is_null(data_get($selectedProduct->normalized_payload, 'stock_quantity', $selectedProduct->stock_quantity)) ? 'Stok bilgisi gelmedi' : number_format((float) data_get($selectedProduct->normalized_payload, 'stock_quantity', $selectedProduct->stock_quantity), 0, ',', '.') }}</div>
                            </div>
                            <div class="pd-profile-info">
                                <div class="pd-profile-info-label">Fiyat snapshot</div>
                                <div class="pd-profile-info-value">{{ is_null(data_get($selectedProduct->normalized_payload, 'list_price')) ? 'Fiyat eksik' : number_format((float) data_get($selectedProduct->normalized_payload, 'list_price'), 2, ',', '.') . ' ' . ($selectedProduct->currency ?: 'TL') }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="pd-detail-box">
                        <div class="pd-detail-box-title">Kategori Override</div>
                        <form method="POST" action="{{ route('admin.super.product-data-hub.supplier-products.override-category', $selectedProduct) }}" id="overrideForm">
                            @csrf
                            <div class="pd-form-grid-2">
                                <div>
                                    <label class="pd-label">Standart kategori</label>
                                    <select name="standard_category_id" class="pd-select" required>
                                        <option value="">Kategori seçin</option>
                                        @foreach($standardCategoryOptions as $category)
                                            <option value="{{ $category->id }}" {{ (int) old('standard_category_id', $selectedProduct->standard_category_id ?: data_get($selectedProduct->normalized_payload, 'category_override_standard_category_id')) === $category->id ? 'selected' : '' }}>
                                                {{ $category->full_path ?: $category->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="pd-checkbox">
                                        <input type="checkbox" name="category_override_apply_to_rule" value="1" {{ old('category_override_apply_to_rule', data_get($selectedProduct->normalized_payload, 'category_override_apply_to_rule', false)) ? 'checked' : '' }}>
                                        Bu kararı kategori eşleme kuralına dönüştürmek için işaretle
                                    </label>
                                </div>
                            </div>
                            <div class="mt-4">
                                <label class="pd-label">Not</label>
                                <textarea name="category_override_note" class="pd-textarea" rows="3" placeholder="Bu ürün tedarikçide yanlış kategori ile geldiği için override verildi.">{{ old('category_override_note', data_get($selectedProduct->normalized_payload, 'category_override_note')) }}</textarea>
                            </div>
                            <div class="pd-hero-actions pd-hero-actions-inline mt-4">
                                <button type="submit" class="pd-btn pd-btn-primary">Kategori Override Kaydet</button>
                                <a href="{{ route('admin.super.product-data-hub.standard-products.index') }}" class="pd-btn pd-btn-light">Standart Ürüne Git</a>
                                <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-warning">Tenant Kataloğa Yansımayı Gör</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="pd-grid pd-grid-2 mt-5">
                    <details class="pd-detail-box" open>
                        <summary class="pd-detail-box-title">Normalize Edilmiş Bilgi</summary>
                        <pre class="pd-json-box">{{ json_encode($selectedProduct->normalized_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </details>
                    <details class="pd-detail-box">
                        <summary class="pd-detail-box-title">Ham Tedarikçi Bilgisi</summary>
                        <pre class="pd-json-box">{{ json_encode($selectedProduct->raw_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </details>
                </div>
            </div>
        </section>
    @endif
</div>
@endsection

@section('side_summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Ürün Çıkış Özeti</h3>
        <div class="pd-status-list">
            <div class="pd-status-row"><span>Tedarikçi ürünü</span><span class="pd-badge pd-badge-blue">{{ $summary['total_supplier_products'] }}</span></div>
            <div class="pd-status-row"><span>Standart ürüne dönüştü</span><span class="pd-badge pd-badge-green">{{ $summary['standardized_products'] }}</span></div>
            <div class="pd-status-row"><span>Tenant kataloğa çıktı</span><span class="pd-badge pd-badge-purple">{{ $summary['tenant_catalog_products'] }}</span></div>
            <div class="pd-status-row"><span>Kategori eksik</span><span class="pd-badge pd-badge-amber">{{ $summary['missing_category'] }}</span></div>
            <div class="pd-status-row"><span>Fiyat eksik</span><span class="pd-badge pd-badge-amber">{{ $summary['missing_price'] }}</span></div>
            <div class="pd-status-row"><span>XML’den çıkan</span><span class="pd-badge pd-badge-red">{{ $summary['missing_from_feed'] }}</span></div>
        </div>
    </div>
</div>
@endsection

@section('bottom_actions')
<div>
    <strong>Tedarikçi ürün akışı:</strong>
    <span class="pd-muted">Kategori override, standart ürün güncelleme ve tenant katalog çıkışını bu bardan yönetin.</span>
</div>
    <div class="pd-bottom-action-buttons">
    @if($selectedProduct)
        <a href="#overrideForm" class="pd-btn pd-btn-light">Kategori Override Kaydet</a>
    @endif
    <a href="{{ route('admin.super.product-data-hub.standard-products.index') }}" class="pd-btn pd-btn-light">Standart Ürüne Güncelle</a>
    <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-primary">Tenant Kataloğa Yansıt</a>
    <a href="{{ route('admin.super.product-data-hub.sources.sync-reports') }}" class="pd-btn pd-btn-warning">Raporu Gör</a>
</div>
@endsection
