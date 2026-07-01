@extends('layouts.prodelya-admin')

@section('title', 'Prodelya Ortak Ürün Havuzu')
@section('page_title', 'Prodelya Ortak Ürün Havuzu')
@section('page_subtitle', 'Bu ekran teknik ortak ürün havuzudur. Günlük satış operasyonu için ana ekran Ürün Paneli olmalıdır; burada parent/grup, varyant ve teknik eşleşme ilişkileri incelenir.')

@section('page_actions')
    <a href="{{ route('admin.super.product-data-hub.product-panel') }}" class="pd-btn pd-btn-primary">Ürün Paneline Git</a>
    <a href="{{ route('admin.super.product-data-hub.index') }}" class="pd-btn pd-btn-light">Genel Bakış</a>
    <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-light">Abone Katalog Yayını</a>
@endsection

@section('content')
@php
    $filters = array_merge([
        'supplier' => '',
        'product_type' => '',
        'sellable' => '',
        'category_status' => '',
        'price_status' => '',
        'stock_status' => '',
        'warning_status' => '',
        'tenant_output' => '',
        'q' => '',
        'limit' => '50',
    ], $filters ?? []);

    $metricCards = [
        ['label' => 'Toplam kayıt', 'value' => $stats['total'], 'class' => 'pd-metric-card-soft-blue'],
        ['label' => 'Parent / Grup', 'value' => $stats['parent'], 'class' => 'pd-metric-card-soft-slate'],
        ['label' => 'Varyant', 'value' => $stats['variant'], 'class' => 'pd-metric-card-soft-purple'],
        ['label' => 'Flat', 'value' => $stats['flat'], 'class' => 'pd-metric-card-soft-green'],
        ['label' => 'Teklifte satılabilir', 'value' => $stats['sellable'], 'class' => 'pd-metric-card-soft-green'],
        ['label' => 'Kataloğa yansıtma engelli', 'value' => $stats['blocked'], 'class' => 'pd-metric-card-soft-amber'],
    ];
@endphp

<div class="pd-hub-family-shell">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Prodelya Ortak Ürün Havuzu</h1>
                    <p class="pd-hero-subtitle">Bu ekran günlük satış kaynağı değildir. Ortak ürün yapısı, parent/grup ilişkileri, kategori durumu ve teknik ürün rolü burada incelenir; günlük operasyon ve freshness takibi için Ürün Paneli kullanılmalıdır.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">Teknik Havuz</span>
                        <span class="pd-badge pd-badge-purple">Parent / Varyant / Flat</span>
                        <span class="pd-badge pd-badge-amber">Teknik Teşhis</span>
                        <span class="pd-badge pd-badge-gray">Günlük Operasyon Değil</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.product-data-hub.product-panel') }}" class="pd-btn pd-btn-primary">Ürün Paneli</a>
                    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Tedarikçi Kaynakları</a>
                    <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-btn pd-btn-light">Kategori Eşleme</a>
                    <a href="{{ route('admin.super.product-data-hub.standard-products.index') }}" class="pd-btn pd-btn-light">Standart Ürünler</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-kpi-strip">
        @foreach($metricCards as $metric)
            <div class="pd-metric-card {{ $metric['class'] }}">
                <div class="pd-metric-card-label">{{ $metric['label'] }}</div>
                <div class="pd-metric-card-value">{{ $metric['value'] }}</div>
            </div>
        @endforeach
    </section>

    <div class="pd-note pd-note-soft-blue pd-gap-bottom-md">
        Bu ekran satış kaynağı değildir. Tenant katalog, teklif ve sipariş tarafı satılabilir projection satırlarından beslenir. Günlük operasyon için Ürün Paneli ekranını kullanın; bu ekran yalnız gelişmiş teknik teşhis ve ürün rolü kontrolü içindir.
    </div>

    <section class="pd-section-card">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Filtreler ve Arama</h3>
                <p class="pd-section-subtitle">Tedarikçi, ürün tipi, satılabilirlik, uyarı, stok ve Abone Firma çıkış durumuna göre ortak havuzu daraltın.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <form method="GET" class="pd-common-filter-grid">
                <div class="pd-field">
                    <label class="pd-label" for="q">Arama</label>
                    <input type="text" id="q" name="q" value="{{ $filters['q'] }}" class="pd-input" placeholder="Kod, ad, grup kodu, varyant, renk, ölçü">
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
                        <option value="catalog_group" @selected($filters['sellable'] === 'catalog_group')>Sadece katalog grup</option>
                        <option value="quote_hidden" @selected($filters['sellable'] === 'quote_hidden')>Teklifte gizli</option>
                    </select>
                </div>
                <div class="pd-field">
                    <label class="pd-label" for="category_status">Kategori durumu</label>
                    <select id="category_status" name="category_status" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="mapped" @selected($filters['category_status'] === 'mapped')>Eşlenmiş</option>
                        <option value="category_missing" @selected($filters['category_status'] === 'category_missing')>Kategori eksik</option>
                        <option value="conflict" @selected($filters['category_status'] === 'conflict')>Conflict</option>
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
                    <label class="pd-label" for="warning_status">Uyarı</label>
                    <select id="warning_status" name="warning_status" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="red_product" @selected($filters['warning_status'] === 'red_product')>Kırmızı Ürün</option>
                        <option value="amber_product" @selected($filters['warning_status'] === 'amber_product')>Turuncu Ürün</option>
                        <option value="net_price" @selected($filters['warning_status'] === 'net_price')>Net fiyat uyarısı</option>
                        <option value="warning" @selected($filters['warning_status'] === 'warning')>Normal uyarı</option>
                    </select>
                </div>
                <div class="pd-field">
                    <label class="pd-label" for="tenant_output">Abone Firma çıkışı</label>
                    <select id="tenant_output" name="tenant_output" class="pd-select">
                        <option value="">Tümü</option>
                        <option value="projected" @selected($filters['tenant_output'] === 'projected')>Abone Firma kataloğuna çıktı</option>
                        <option value="not_projected" @selected($filters['tenant_output'] === 'not_projected')>Abone Firma kataloğuna çıkmadı</option>
                        <option value="blocked" @selected($filters['tenant_output'] === 'blocked')>Kataloğa yansıtma engelli</option>
                        <option value="hidden" @selected($filters['tenant_output'] === 'hidden')>Teklifte gizli</option>
                    </select>
                </div>
                <div class="pd-field">
                    <label class="pd-label" for="limit">Kayıt limiti</label>
                    <select id="limit" name="limit" class="pd-select">
                        <option value="50" @selected($filters['limit'] === '50')>50</option>
                        <option value="100" @selected($filters['limit'] === '100')>100</option>
                        <option value="250" @selected($filters['limit'] === '250')>250</option>
                        <option value="500" @selected($filters['limit'] === '500')>500</option>
                        <option value="all" @selected($filters['limit'] === 'all')>Tümünü göster</option>
                    </select>
                </div>
                <div class="pd-common-filter-actions">
                    <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                    <a href="{{ route('admin.super.product-data-hub.common-products') }}" class="pd-btn pd-btn-light">Temizle</a>
                </div>
            </form>

            @if($showAllWarning)
                <div class="pd-note pd-note-amber pd-gap-top-md">
                    Tüm ürünleri göstermek ekranı yavaşlatabilir.
                </div>
            @endif
        </div>
    </section>

    <section class="pd-main-utility-grid pd-common-product-workspace">
        <div class="pd-section-card pd-product-list-card">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Ortak Ürün Listesi</h3>
                    <p class="pd-section-subtitle">Parent, varyant ve flat kayıtları tek standart satırda görün. Teklifte satılabilirlik ve Abone Firma çıkış durumu aynı listede izlenir.</p>
                </div>
            </div>
            <div class="pd-section-body">
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
                                <th>Abone Firma çıkışı</th>
                                <th>Son sync</th>
                                <th>Aksiyonlar</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                                <tr class="{{ ($selectedRow['row_key'] ?? null) === $row['row_key'] ? 'pd-table-row-selected' : '' }}">
                                    <td>
                                        @if(!empty($row['urun_resim']))
                                            <img src="{{ $row['urun_resim'] }}" alt="{{ $row['urun_adi'] }}" class="pd-product-thumb-sm">
                                        @else
                                            <span class="pd-badge pd-badge-gray">Yok</span>
                                        @endif
                                    </td>
                                    <td><span class="pd-badge pd-badge-blue pd-code-badge">{{ $row['urun_kodu'] ?: '-' }}</span></td>
                                    <td class="pd-product-name-cell" title="{{ $row['urun_adi'] }}">{{ $row['urun_adi'] }}</td>
                                    <td class="pd-supplier-cell">{{ $row['urun_tedarikci'] }}</td>
                                    <td>
                                        <span class="pd-badge pd-badge-{{ $row['product_type'] === 'parent' ? 'purple' : ($row['product_type'] === 'variant' ? 'green' : 'blue') }}">
                                            {{ $row['product_type'] === 'parent' ? 'Parent / Grup' : ($row['product_type'] === 'variant' ? 'Varyant' : 'Flat') }}
                                        </span>
                                    </td>
                                    <td class="pd-nowrap">{{ $row['parent_grup_kodu'] ?: '-' }}</td>
                                    <td class="pd-nowrap">{{ $row['varyant_kodu'] ?: '-' }}</td>
                                    <td class="pd-compact-meta-cell">{{ collect([$row['urun_renk'], $row['urun_olcu'], $row['urun_ebat']])->filter()->implode(' / ') ?: '-' }}</td>
                                    <td class="pd-category-cell" title="{{ $row['urun_kategori'] ?: '-' }}">{{ $row['urun_kategori'] ?: '-' }}</td>
                                    <td class="pd-number-cell">{{ is_numeric($row['urun_stok']) ? number_format((float) $row['urun_stok'], 0, ',', '.') : '-' }}</td>
                                    <td class="pd-number-cell">{{ is_null($row['urun_fiyat']) ? 'Fiyat eksik' : number_format((float) $row['urun_fiyat'], 2, ',', '.') }}</td>
                                    <td class="pd-nowrap">{{ is_null($row['urun_kdv']) ? '-' : '%' . number_format((float) $row['urun_kdv'], 0, ',', '.') }}</td>
                                    <td>
                                        <div class="pd-chip-group">
                                            @if(in_array('red_product', $row['warning_tags'], true))
                                                <span class="pd-badge pd-badge-red">Kırmızı Ürün</span>
                                            @endif
                                            @if(in_array('amber_product', $row['warning_tags'], true))
                                                <span class="pd-badge pd-badge-amber">Turuncu Ürün</span>
                                            @endif
                                            @if(in_array('net_price', $row['warning_tags'], true))
                                                <span class="pd-badge pd-badge-amber">Net fiyat uyarısı</span>
                                            @endif
                                            @if(in_array('category_missing', $row['warning_tags'], true))
                                                <span class="pd-badge pd-badge-gray">Kategori eksik</span>
                                            @endif
                                            @if(in_array('stock_missing', $row['warning_tags'], true))
                                                <span class="pd-badge pd-badge-red">Stok yok</span>
                                            @endif
                                            @if(empty($row['warning_tags']))
                                                <span class="pd-badge pd-badge-green">Normal</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        <span class="pd-badge pd-badge-{{ $row['satilabilir_mi'] ? 'green' : 'gray' }}">
                                            {{ $row['satilabilir_mi'] ? 'Evet' : 'Hayır' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="pd-badge pd-badge-{{ $row['tenant_katalog_durumu'] === 'projected' ? 'green' : ($row['tenant_katalog_durumu'] === 'blocked' ? 'amber' : 'gray') }}">
                                            {{ $row['tenant_output_label'] }}
                                        </span>
                                    </td>
                                    <td class="pd-nowrap">{{ $row['son_sync'] ?: '-' }}</td>
                                    <td class="pd-actions-cell">
                                        <div class="pd-actions">
                                            <a href="{{ route('admin.super.product-data-hub.common-products', array_merge(request()->query(), ['selected' => $row['row_key']])) }}" class="pd-btn pd-btn-sm pd-btn-light">İncele</a>
                                            <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-btn pd-btn-sm pd-btn-light">Kategori</a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="17">
                                        <div class="pd-note">Filtreye uyan ortak ürün kaydı bulunamadı.</div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="pd-common-pagination">
                    <div class="pd-source-meta-line">
                        Toplam kayıt: <strong>{{ number_format($rows->total(), 0, ',', '.') }}</strong> ·
                        Gösterilen: <strong>{{ $rows->count() }}</strong>
                    </div>
                    <div class="pd-actions">
                        @if($rows->onFirstPage())
                            <span class="pd-btn pd-btn-sm pd-btn-light pd-sidebar-item-muted">Önceki</span>
                        @else
                            <a href="{{ $rows->previousPageUrl() }}" class="pd-btn pd-btn-sm pd-btn-light">Önceki</a>
                        @endif
                        <span class="pd-badge pd-badge-blue">Sayfa {{ $rows->currentPage() }} / {{ max(1, $rows->lastPage()) }}</span>
                        @if($rows->hasMorePages())
                            <a href="{{ $rows->nextPageUrl() }}" class="pd-btn pd-btn-sm pd-btn-light">Sonraki</a>
                        @else
                            <span class="pd-btn pd-btn-sm pd-btn-light pd-sidebar-item-muted">Sonraki</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="pd-section-card pd-section-card-soft-slate pd-product-detail-card">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Ürün Detayı</h3>
                    <p class="pd-section-subtitle">Ham bilgi, normalize alanlar, Abone Firma çıkışı ve parent/varyant ilişkisini tek panelde görün.</p>
                </div>
            </div>
            <div class="pd-section-body">
                @if($selectedRow)
                    <div class="pd-status-list">
                        <div class="pd-status-row"><span>Ürün kodu</span><span class="font-medium">{{ $selectedRow['urun_kodu'] ?: '-' }}</span></div>
                        <div class="pd-status-row"><span>Ürün adı</span><span class="font-medium">{{ $selectedRow['urun_adi'] ?: '-' }}</span></div>
                        <div class="pd-status-row"><span>Tedarikçi</span><span class="font-medium">{{ $selectedRow['urun_tedarikci'] ?: '-' }}</span></div>
                        <div class="pd-status-row"><span>Ürün tipi</span><span class="font-medium">{{ strtoupper($selectedRow['product_type']) }}</span></div>
                        <div class="pd-status-row"><span>Satılabilir</span><span class="font-medium">{{ $selectedRow['satilabilir_mi'] ? 'Teklifte satılabilir' : 'Sadece katalog grup' }}</span></div>
                        <div class="pd-status-row"><span>Grup kodu</span><span class="font-medium">{{ $selectedRow['parent_grup_kodu'] ?: '-' }}</span></div>
                        <div class="pd-status-row"><span>Varyant kodu</span><span class="font-medium">{{ $selectedRow['varyant_kodu'] ?: '-' }}</span></div>
                        <div class="pd-status-row"><span>Kategori durumu</span><span class="font-medium">{{ $selectedRow['kategori_esleme_durumu'] }}</span></div>
                        <div class="pd-status-row"><span>Abone Firma çıkışı</span><span class="font-medium">{{ $selectedRow['tenant_output_label'] }}</span></div>
                        <div class="pd-status-row"><span>Son sync</span><span class="font-medium">{{ $selectedRow['son_sync'] ?: '-' }}</span></div>
                    </div>

                    <div class="pd-summary-section">
                        <h4 class="pd-summary-section-title">Ortak Alan Seti</h4>
                        <div class="pd-note pd-note-soft-blue">
                            <strong>urun_kodu:</strong> {{ $selectedRow['urun_kodu'] ?: '-' }}<br>
                            <strong>urun_adi:</strong> {{ $selectedRow['urun_adi'] ?: '-' }}<br>
                            <strong>urun_kategori:</strong> {{ $selectedRow['urun_kategori'] ?: '-' }}<br>
                            <strong>urun_stok:</strong> {{ is_numeric($selectedRow['urun_stok']) ? number_format((float) $selectedRow['urun_stok'], 0, ',', '.') : '-' }}<br>
                            <strong>urun_fiyat:</strong> {{ is_null($selectedRow['urun_fiyat']) ? 'Fiyat eksik' : number_format((float) $selectedRow['urun_fiyat'], 2, ',', '.') }}<br>
                            <strong>urun_kdv:</strong> {{ is_null($selectedRow['urun_kdv']) ? '-' : '%' . number_format((float) $selectedRow['urun_kdv'], 0, ',', '.') }}<br>
                            <strong>parent_grup_kodu:</strong> {{ $selectedRow['parent_grup_kodu'] ?: '-' }}<br>
                            <strong>varyant_kodu:</strong> {{ $selectedRow['varyant_kodu'] ?: '-' }}
                        </div>
                    </div>
                @else
                    <div class="pd-note">Detay görmek için listeden bir ürün seçin.</div>
                @endif
            </div>
        </div>
    </section>
</div>
@endsection

@section('side_summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Ortak Havuz Özeti</h3>

        <div class="pd-status-list">
            <div class="pd-status-row"><span>Toplam kayıt</span><span class="pd-badge pd-badge-blue">{{ $stats['total'] }}</span></div>
            <div class="pd-status-row"><span>Teklifte satılabilir</span><span class="pd-badge pd-badge-green">{{ $stats['sellable'] }}</span></div>
            <div class="pd-status-row"><span>Sadece katalog grup</span><span class="pd-badge pd-badge-gray">{{ $stats['catalog_only'] }}</span></div>
            <div class="pd-status-row"><span>Abone Firma çıkışı var</span><span class="pd-badge pd-badge-green">{{ $stats['projected'] }}</span></div>
            <div class="pd-status-row"><span>Kataloğa yansıtma engelli</span><span class="pd-badge pd-badge-amber">{{ $stats['blocked'] }}</span></div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Akış</h4>
            <div class="pd-summary-action-list">
                <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-summary-action"><span>1. Tedarikçi Kaynakları</span><span class="pd-badge pd-badge-blue">Kaynak</span></a>
                <a href="{{ route('admin.super.product-data-hub.raw-products.index') }}" class="pd-summary-action"><span>2. Normalize / Staging</span><span class="pd-badge pd-badge-purple">Staging</span></a>
                <a href="{{ route('admin.super.product-data-hub.common-products') }}" class="pd-summary-action"><span>3. Ortak Ürün Havuzu</span><span class="pd-badge pd-badge-green">Merkez</span></a>
                <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-summary-action"><span>4. Abone Katalog Yayını</span><span class="pd-badge pd-badge-amber">Yayın</span></a>
            </div>
        </div>
    </div>
</div>
@endsection

@section('bottom_actions')
<div>
    <strong>Ortak ürün akışı:</strong>
    <span class="pd-muted">Tedarikçi ham verisi burada Prodelya ortak ürün diline çevrilir ve teklif, sipariş, katalog, export çıkışlarını besler.</span>
</div>
<div class="pd-bottom-action-buttons">
    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Tedarikçi Kaynakları</a>
    <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-btn pd-btn-light">Kategori Eşleme</a>
    <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-primary">Abone Katalog Yayını</a>
</div>
@endsection
