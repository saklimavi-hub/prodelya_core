@extends('layouts.prodelya-admin')

@section('title', 'Standart Ürünler')
@section('page_title', 'Standart Ürünler')
@section('page_subtitle', 'Ortak Ürün Havuzu verisini teknik standart ürün görünümüyle filtreleyin, sayfalayın ve tenant çıkış durumunu izleyin.')

@section('page_actions')
    <a href="{{ route('admin.super.product-data-hub.common-products') }}" class="pd-btn pd-btn-light">Ortak Ürün Havuzu</a>
    <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-primary">Tenant Çıkışları</a>
@endsection

@section('content')
@php
    $filters = array_merge([
        'q' => '',
        'supplier' => '',
        'product_type' => '',
        'sellable' => '',
        'category_status' => '',
        'price_status' => '',
        'stock_status' => '',
        'warning_status' => '',
        'tenant_projection_status' => '',
        'limit' => '50',
    ], $filters ?? []);

    $metricCards = [
        ['label' => 'Toplam standart ürün', 'value' => $stats['total'], 'class' => 'pd-metric-card-soft-blue'],
        ['label' => 'Parent / Grup', 'value' => $stats['parent'], 'class' => 'pd-metric-card-soft-slate'],
        ['label' => 'Varyant', 'value' => $stats['variant'], 'class' => 'pd-metric-card-soft-purple'],
        ['label' => 'Flat', 'value' => $stats['flat'], 'class' => 'pd-metric-card-soft-green'],
        ['label' => 'Teklifte satılabilir', 'value' => $stats['sellable'], 'class' => 'pd-metric-card-soft-green'],
        ['label' => 'Tenant çıkışı bekleyen', 'value' => $stats['tenant_pending'], 'class' => 'pd-metric-card-soft-amber'],
        ['label' => 'Kategori eksik', 'value' => $stats['missing_category'], 'class' => 'pd-metric-card-soft-amber'],
        ['label' => 'Fiyat eksik', 'value' => $stats['missing_price'], 'class' => 'pd-metric-card-soft-red'],
    ];
@endphp

<div class="pd-hub-family-shell">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Standart Ürünler</h1>
                    <p class="pd-hero-subtitle">Ortak Ürün Havuzu ana merkezdir; bu ekran aynı veriyi teknik standart ürün ve projection kontrolleriyle listeler.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">Standart Ürün</span>
                        <span class="pd-badge pd-badge-green">Parent / Varyant / Flat</span>
                        <span class="pd-badge pd-badge-purple">Tenant Projection</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.product-data-hub.common-products') }}" class="pd-btn pd-btn-light">Ortak Ürün Havuzu</a>
                    <a href="{{ route('admin.super.product-data-hub.raw-products.index') }}" class="pd-btn pd-btn-light">Ham Ürün Havuzu</a>
                    <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-primary">Tenant Çıkışları</a>
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

    <section class="pd-section-card pd-product-list-card pd-product-list-card-full">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Filtreler ve Arama</h3>
                <p class="pd-section-subtitle">Ortak Ürün Havuzu ile aynı filtre, limit ve sayfalama standardı kullanılır.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="GET" class="pd-common-filter-grid">
                <div class="pd-field">
                    <label class="pd-label" for="q">Arama</label>
                    <input id="q" name="q" value="{{ $filters['q'] }}" class="pd-input" placeholder="Kod, ad, tedarikçi kodu, grup, varyant, renk">
                </div>
                <div class="pd-field">
                    <label class="pd-label" for="supplier">Tedarikçi</label>
                    <select id="supplier" name="supplier" class="pd-select">
                        <option value="">Tümü</option>
                        @foreach($supplierOptions as $supplierOption)
                            <option value="{{ $supplierOption }}" @selected($filters['supplier'] === $supplierOption)>{{ $supplierOption }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pd-field">
                    <label class="pd-label" for="product_type">Ürün tipi</label>
                    <select id="product_type" name="product_type" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="parent" @selected($filters['product_type'] === 'parent')>Parent / Grup</option>
                        <option value="variant" @selected($filters['product_type'] === 'variant')>Varyant</option>
                        <option value="flat" @selected($filters['product_type'] === 'flat')>Flat</option>
                    </select>
                </div>
                <div class="pd-field">
                    <label class="pd-label" for="sellable">Satılabilirlik</label>
                    <select id="sellable" name="sellable" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="sellable" @selected($filters['sellable'] === 'sellable')>Teklifte satılabilir</option>
                        <option value="not_sellable" @selected($filters['sellable'] === 'not_sellable')>Teklifte satılamaz</option>
                        <option value="catalog_group" @selected($filters['sellable'] === 'catalog_group')>Sadece katalog grup</option>
                    </select>
                </div>
                <div class="pd-field">
                    <label class="pd-label" for="category_status">Kategori durumu</label>
                    <select id="category_status" name="category_status" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="mapped" @selected($filters['category_status'] === 'mapped')>Kategorisi eşlenmiş</option>
                        <option value="category_missing" @selected($filters['category_status'] === 'category_missing')>Kategori eksik</option>
                        <option value="conflict" @selected($filters['category_status'] === 'conflict')>Conflict / kontrol gerekli</option>
                        <option value="override" @selected($filters['category_status'] === 'override')>Override var</option>
                    </select>
                </div>
                <div class="pd-field">
                    <label class="pd-label" for="price_status">Fiyat durumu</label>
                    <select id="price_status" name="price_status" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="price_available" @selected($filters['price_status'] === 'price_available')>Fiyat var</option>
                        <option value="price_missing" @selected($filters['price_status'] === 'price_missing')>Fiyat eksik</option>
                        <option value="net_price" @selected($filters['price_status'] === 'net_price')>Net fiyat uyarılı</option>
                        <option value="fixed_price" @selected($filters['price_status'] === 'fixed_price')>Sabit fiyat uyarılı</option>
                    </select>
                </div>
                <div class="pd-field">
                    <label class="pd-label" for="stock_status">Stok durumu</label>
                    <select id="stock_status" name="stock_status" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="in_stock" @selected($filters['stock_status'] === 'in_stock')>Stok var</option>
                        <option value="out_of_stock" @selected($filters['stock_status'] === 'out_of_stock')>Stok yok</option>
                        <option value="stock_unknown" @selected($filters['stock_status'] === 'stock_unknown')>Stok bilgisi gelmedi</option>
                    </select>
                </div>
                <div class="pd-field">
                    <label class="pd-label" for="warning_status">Uyarı durumu</label>
                    <select id="warning_status" name="warning_status" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="red_product" @selected($filters['warning_status'] === 'red_product')>Kırmızı ürün</option>
                        <option value="amber_product" @selected($filters['warning_status'] === 'amber_product')>Turuncu ürün</option>
                        <option value="net_price" @selected($filters['warning_status'] === 'net_price')>Net fiyat uyarısı</option>
                        <option value="clean" @selected($filters['warning_status'] === 'clean')>Uyarısız</option>
                    </select>
                </div>
                <div class="pd-field">
                    <label class="pd-label" for="tenant_projection_status">Tenant çıkışı</label>
                    <select id="tenant_projection_status" name="tenant_projection_status" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="projected" @selected($filters['tenant_projection_status'] === 'projected')>Tenant kataloğa çıkmış</option>
                        <option value="not_projected" @selected($filters['tenant_projection_status'] === 'not_projected')>Tenant kataloğa çıkmamış</option>
                        <option value="pending" @selected($filters['tenant_projection_status'] === 'pending')>Projection bekliyor</option>
                        <option value="blocked" @selected($filters['tenant_projection_status'] === 'blocked')>Projection blocked</option>
                    </select>
                </div>
                <div class="pd-field">
                    <label class="pd-label" for="limit">Kayıt limiti</label>
                    <select id="limit" name="limit" class="pd-select">
                        <option value="50" @selected($filters['limit'] === '50')>50</option>
                        <option value="100" @selected($filters['limit'] === '100')>100</option>
                        <option value="250" @selected($filters['limit'] === '250')>250</option>
                        <option value="500" @selected($filters['limit'] === '500')>500</option>
                        <option value="all" @selected($filters['limit'] === 'all')>Tümü</option>
                    </select>
                </div>
                <div class="pd-common-filter-actions">
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.super.product-data-hub.standard-products.index') }}" class="pd-btn pd-btn-light">Temizle</a>
                </div>
            </form>

            @if($showAllWarning)
                <div class="pd-note pd-note-amber" style="margin-top:16px;">Tüm ürünleri göstermek ekranı yavaşlatabilir.</div>
            @endif
        </div>
    </section>

    <section class="pd-section-card">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Standart Ürün Listesi</h3>
                <p class="pd-section-subtitle">Kod, tip, satılabilirlik, kategori, stok, fiyat ve tenant projection durumunu aynı tabloda izleyin.</p>
            </div>
        </div>
        <div class="pd-section-body">
            @if($products->isEmpty())
                <div class="pd-note">Filtreye uyan standart ürün kaydı bulunamadı.</div>
            @else
                <div class="pd-table-wrap pd-product-data-table-wrap">
                    <table class="pd-table pd-product-data-table">
                        <thead>
                            <tr>
                                <th>Görsel</th>
                                <th>Ürün kodu</th>
                                <th>Ürün adı</th>
                                <th>Tedarikçi</th>
                                <th>Ürün tipi</th>
                                <th>Grup kodu</th>
                                <th>Varyant kodu</th>
                                <th>Renk / Ölçü</th>
                                <th>Standart kategori</th>
                                <th>Stok</th>
                                <th>Liste fiyatı</th>
                                <th>KDV</th>
                                <th>Uyarılar</th>
                                <th>Satılabilir</th>
                                <th>Tenant çıkışı</th>
                                <th>Son sync</th>
                                <th>Aksiyonlar</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($products as $row)
                                <tr>
                                    <td>
                                        @if(!empty($row['image_url']))
                                            <img src="{{ $row['image_url'] }}" alt="{{ $row['product_name'] }}" class="pd-product-thumb-sm">
                                        @else
                                            <span class="pd-badge pd-badge-gray">Yok</span>
                                        @endif
                                    </td>
                                    <td><span class="pd-badge pd-badge-blue pd-code-badge">{{ $row['product_code'] ?: '-' }}</span></td>
                                    <td class="pd-product-name-cell" title="{{ $row['product_name'] }}">{{ $row['product_name'] }}</td>
                                    <td class="pd-supplier-cell">{{ $row['supplier_name'] }}</td>
                                    <td>
                                        <span class="pd-badge pd-badge-{{ $row['product_type'] === 'parent' ? 'purple' : ($row['product_type'] === 'variant' ? 'green' : 'blue') }}">
                                            {{ $row['type_label'] }}
                                        </span>
                                    </td>
                                    <td class="pd-nowrap">{{ $row['group_code'] ?: '-' }}</td>
                                    <td class="pd-nowrap">{{ $row['variant_code'] ?: '-' }}</td>
                                    <td class="pd-compact-meta-cell">{{ collect([$row['color'], $row['measure']])->filter()->implode(' / ') ?: '-' }}</td>
                                    <td class="pd-category-cell" title="{{ $row['category'] ?: '-' }}">{{ $row['category'] ?: '-' }}</td>
                                    <td class="pd-number-cell">{{ is_numeric($row['stock']) ? number_format((float) $row['stock'], 0, ',', '.') : '-' }}</td>
                                    <td class="pd-number-cell">{{ is_null($row['list_price']) ? 'Fiyat eksik' : number_format((float) $row['list_price'], 2, ',', '.') }}</td>
                                    <td class="pd-nowrap">{{ is_null($row['vat_rate']) ? '-' : '%' . number_format((float) $row['vat_rate'], 0, ',', '.') }}</td>
                                    <td>
                                        <div class="pd-chip-group">
                                            @if(in_array('red_product', $row['warning_tags'], true))
                                                <span class="pd-badge pd-badge-red">Kırmızı ürün</span>
                                            @endif
                                            @if(in_array('amber_product', $row['warning_tags'], true))
                                                <span class="pd-badge pd-badge-amber">Turuncu ürün</span>
                                            @endif
                                            @if(in_array('net_price', $row['warning_tags'], true))
                                                <span class="pd-badge pd-badge-amber">Net fiyat</span>
                                            @endif
                                            @if(empty($row['warning_tags']))
                                                <span class="pd-badge pd-badge-green">Uyarısız</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        <span class="pd-badge pd-badge-{{ $row['is_sellable'] ? 'green' : 'gray' }}">{{ $row['sellable_label'] }}</span>
                                    </td>
                                    <td>
                                        <span class="pd-badge pd-badge-{{ $row['tenant_projection_status'] === 'projected' ? 'green' : ($row['tenant_projection_status'] === 'blocked' ? 'amber' : 'gray') }}">
                                            {{ $row['tenant_projection_label'] }}
                                        </span>
                                    </td>
                                    <td class="pd-nowrap">{{ $row['last_sync'] ?: '-' }}</td>
                                    <td class="pd-actions-cell">
                                        <div class="pd-actions">
                                            <a href="{{ route('admin.super.product-data-hub.supplier-products', ['selected_product_id' => $row['raw_product_id']]) }}" class="pd-btn pd-btn-sm pd-btn-light">İncele</a>
                                            <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-sm pd-btn-light">Tenant Katalog</a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="pd-common-pagination">
                    <div class="pd-source-meta-line">
                        Toplam {{ number_format($products->total(), 0, ',', '.') }} kayıt ·
                        {{ number_format($products->firstItem() ?? 0, 0, ',', '.') }} - {{ number_format($products->lastItem() ?? 0, 0, ',', '.') }} arası gösteriliyor
                    </div>
                    <div class="pd-actions">
                        @if($products->onFirstPage())
                            <span class="pd-btn pd-btn-sm pd-btn-light pd-sidebar-item-muted">Önceki</span>
                        @else
                            <a href="{{ $products->previousPageUrl() }}" class="pd-btn pd-btn-sm pd-btn-light">Önceki</a>
                        @endif
                        <span class="pd-badge pd-badge-blue">Sayfa {{ $products->currentPage() }} / {{ max(1, $products->lastPage()) }}</span>
                        @if($products->hasMorePages())
                            <a href="{{ $products->nextPageUrl() }}" class="pd-btn pd-btn-sm pd-btn-light">Sonraki</a>
                        @else
                            <span class="pd-btn pd-btn-sm pd-btn-light pd-sidebar-item-muted">Sonraki</span>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </section>
</div>
@endsection

@section('side_summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Standart Ürün Özeti</h3>

        <div class="pd-status-list">
            <div class="pd-status-row"><span>Toplam ürün</span><span class="pd-badge pd-badge-blue">{{ $stats['total'] }}</span></div>
            <div class="pd-status-row"><span>Teklifte satılabilir</span><span class="pd-badge pd-badge-green">{{ $stats['sellable'] }}</span></div>
            <div class="pd-status-row"><span>Parent / Grup</span><span class="pd-badge pd-badge-gray">{{ $stats['parent'] }}</span></div>
            <div class="pd-status-row"><span>Varyant</span><span class="pd-badge pd-badge-purple">{{ $stats['variant'] }}</span></div>
            <div class="pd-status-row"><span>Kategori eksik</span><span class="pd-badge pd-badge-amber">{{ $stats['missing_category'] }}</span></div>
            <div class="pd-status-row"><span>Fiyat eksik</span><span class="pd-badge pd-badge-amber">{{ $stats['missing_price'] }}</span></div>
            <div class="pd-status-row"><span>Uyarılı</span><span class="pd-badge pd-badge-red">{{ $stats['warning'] }}</span></div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Ana Merkez</h4>
            <div class="pd-summary-action-list">
                <a href="{{ route('admin.super.product-data-hub.common-products') }}" class="pd-summary-action"><span>Ortak Ürün Havuzu</span><span class="pd-badge pd-badge-green">Ana ekran</span></a>
                <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-summary-action"><span>Tenant çıkışları</span><span class="pd-badge pd-badge-blue">Projection</span></a>
            </div>
        </div>
    </div>
</div>
@endsection

@section('bottom_actions')
<div>
    <strong>Standart ürün akışı:</strong>
    <span class="pd-muted">Bu teknik görünüm Ortak Ürün Havuzu ile aynı filtre ve sayfalama standardını kullanır.</span>
</div>
<div class="pd-bottom-action-buttons">
    <a href="{{ route('admin.super.product-data-hub.common-products') }}" class="pd-btn pd-btn-primary">Ortak Ürün Havuzu</a>
    <a href="{{ route('admin.super.product-data-hub.raw-products.index') }}" class="pd-btn pd-btn-light">Ham Ürün Havuzu</a>
    <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-light">Tenant Çıkışları</a>
</div>
@endsection
