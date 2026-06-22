@extends('layouts.prodelya-admin')

@section('title', 'Tenant Katalog Çıkışı')
@section('page_title', 'Tenant Katalog Çıkışı')
@section('page_subtitle', 'Product Data Hub içinde temizlenen verinin tenant katalog katmanına ve Gelişmiş Ürün ve Katalog ekranına nasıl aktığını buradan takip edin.')

@section('page_actions')
<div class="pd-actions">
    <a href="{{ route('admin.super.product-data-hub.index') }}" class="pd-btn pd-btn-light">Hub Özeti</a>
    <a href="{{ route('admin.super.product-data-hub.standard-products.index') }}" class="pd-btn pd-btn-light">Standart Ürün Havuzu</a>
    <a href="{{ route('admin.super.tenant-supplier-access.index') }}" class="pd-btn pd-btn-light">Tenant Erişimleri</a>
    <a href="{{ route('admin.catalog.index') }}" class="pd-btn pd-btn-primary">Gelişmiş Ürün ve Katalog</a>
</div>
@endsection

@section('content')
@php
    $metricCards = [
        ['label' => 'Toplam standart ürün', 'value' => $catalogOutput['total_standard_products'], 'class' => 'pd-metric-card-soft-blue'],
        ['label' => 'Toplam varyant', 'value' => $catalogOutput['total_variants'], 'class' => 'pd-metric-card-soft-purple'],
        ['label' => 'Tenant’a açık ürün', 'value' => $catalogOutput['tenant_open_products'], 'class' => 'pd-metric-card-soft-green'],
        ['label' => 'Tenant’a kapalı ürün', 'value' => $catalogOutput['tenant_closed_products'], 'class' => 'pd-metric-card-soft-slate'],
        ['label' => 'Kategori eksiği', 'value' => $catalogOutput['category_missing_products'], 'class' => 'pd-metric-card-soft-amber'],
        ['label' => 'Uyarılı ürün', 'value' => $catalogOutput['warning_products'], 'class' => 'pd-metric-card-soft-red'],
    ];
@endphp

<div class="pd-hub-family-shell">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Katalog Çıkışı</h1>
                    <p class="pd-hero-subtitle">XML toplama hattından çıkan standart ürünlerin tenant katalog projeksiyonuna ve Gelişmiş Ürün ve Katalog katmanına geçişini yönetin.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">Standart Ürün Havuzu</span>
                        <span class="pd-badge pd-badge-green">Tenant Katalog</span>
                        <span class="pd-badge pd-badge-purple">Gelişmiş Ürün ve Katalog</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <form action="{{ route('admin.catalog.project') }}" method="POST">
                        @csrf
                        <button type="submit" class="pd-btn pd-btn-primary">Tenant katalog projeksiyonunu güncelle</button>
                    </form>
                    <a href="{{ route('admin.super.product-data-hub.category-mappings.index', ['queue' => 'pending']) }}" class="pd-btn pd-btn-warning">Eksik kategorili ürünleri göster</a>
                    <a href="{{ route('admin.super.tenant-supplier-access.index') }}" class="pd-btn pd-btn-light">Tenant erişimlerini düzenle</a>
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
                <h3 class="pd-section-title">Ürün Veri Hattı</h3>
                <p class="pd-section-subtitle">Global kaynaklardan tenant kataloğa kadar geçen tüm adımlar burada görünür.</p>
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

    <section class="pd-section-card pd-section-card-soft-purple">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Katalog Çıkışı Özeti</h3>
                <p class="pd-section-subtitle">Standart ürün, tenant katalog ve eksik veri alanlarını tek bakışta görün.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-grid pd-grid-3">
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Tedarikçi ürünleri</div>
                    <div class="pd-profile-info-value">{{ $catalogOutput['supplier_products'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Kategori eşlemesi tamamlanan</div>
                    <div class="pd-profile-info-value">{{ $catalogOutput['category_mapped_products'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Kategori eşlemesi eksik</div>
                    <div class="pd-profile-info-value">{{ $catalogOutput['category_missing_products'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Fiyatı eksik ürün</div>
                    <div class="pd-profile-info-value">{{ $catalogOutput['missing_price_products'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Stok değişen ürün</div>
                    <div class="pd-profile-info-value">{{ $catalogOutput['stock_changed_products'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Fiyat değişen ürün</div>
                    <div class="pd-profile-info-value">{{ $catalogOutput['price_changed_products'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Görseli eksik ürün</div>
                    <div class="pd-profile-info-value">{{ $catalogOutput['missing_image_products'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Local stok öncelikli</div>
                    <div class="pd-profile-info-value">{{ $catalogOutput['local_stock_priority_products'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Kategori eksiği yüzünden bekleyen</div>
                    <div class="pd-profile-info-value">{{ $catalogOutput['projection_blocked_missing_category'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Uyarıyla çıkan ürün</div>
                    <div class="pd-profile-info-value">{{ $catalogOutput['projection_warning_outputs'] }}</div>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Tedarikçi Bazlı Çıkış Görünümü</h3>
                <p class="pd-section-subtitle">Her tedarikçi için kaynak, staging, standart ürün ve tenant katalog sayıları.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Tedarikçi</th>
                            <th>Kaynak</th>
                            <th>Preview</th>
                            <th>Ham Ürün</th>
                            <th>Standart Ürün</th>
                            <th>Tenant Katalog</th>
                            <th>Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($supplierRows as $row)
                            <tr>
                                <td><strong>{{ $row['supplier']->name }}</strong></td>
                                <td>{{ $row['source_count'] }}</td>
                                <td>{{ $row['preview_records'] }}</td>
                                <td>{{ $row['raw_products'] }}</td>
                                <td>{{ $row['standard_products'] }}</td>
                                <td>{{ $row['tenant_catalog_products'] }}</td>
                                <td>
                                    <div class="pd-actions">
                                        <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light pd-btn-sm">Kaynak</a>
                                        <a href="{{ route('admin.super.product-data-hub.standard-products.index') }}" class="pd-btn pd-btn-light pd-btn-sm">Standart Ürün</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection

@section('side_summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Çıkış Özeti</h3>

        <div class="pd-status-list">
            <div class="pd-status-row"><span>Standart ürün</span><span class="pd-badge pd-badge-blue">{{ $catalogOutput['total_standard_products'] }}</span></div>
            <div class="pd-status-row"><span>Tenant açık</span><span class="pd-badge pd-badge-green">{{ $catalogOutput['tenant_open_products'] }}</span></div>
            <div class="pd-status-row"><span>Tenant kapalı</span><span class="pd-badge pd-badge-gray">{{ $catalogOutput['tenant_closed_products'] }}</span></div>
            <div class="pd-status-row"><span>Kategori eksiği</span><span class="pd-badge pd-badge-amber">{{ $catalogOutput['category_missing_products'] }}</span></div>
            <div class="pd-status-row"><span>Uyarılı ürün</span><span class="pd-badge pd-badge-red">{{ $catalogOutput['warning_products'] }}</span></div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Hızlı Aksiyonlar</h4>
            <div class="pd-summary-action-list">
                <form action="{{ route('admin.catalog.project') }}" method="POST">
                    @csrf
                    <button type="submit" class="pd-summary-action pd-summary-action-button">
                        <span>Tenant katalog projeksiyonunu güncelle</span><span class="pd-badge pd-badge-blue">Projeksiyon</span>
                    </button>
                </form>
                <a href="{{ route('admin.super.product-data-hub.category-mappings.index', ['queue' => 'pending']) }}" class="pd-summary-action"><span>Eksik kategorili ürünleri göster</span><span class="pd-badge pd-badge-amber">Eksik</span></a>
                <a href="{{ route('admin.super.tenant-supplier-access.index') }}" class="pd-summary-action"><span>Tenant erişimlerini düzenle</span><span class="pd-badge pd-badge-green">Yetki</span></a>
                <a href="{{ route('admin.catalog.index') }}" class="pd-summary-action"><span>Gelişmiş Ürün ve Katalog’a git</span><span class="pd-badge pd-badge-purple">Katalog</span></a>
            </div>
        </div>

        <div class="pd-side-note">Product Data Hub temizler, standartlaştırır ve tenant kataloğa çıkarır. Teklif/sipariş ürün seçimi bu temiz katalog katmanından beslenmelidir.</div>
    </div>
</div>
@endsection

@section('bottom_actions')
<div>
    <strong>Katalog çıkışı:</strong>
    <span class="pd-muted">Standart ürün havuzu, tenant erişimi ve katalog projeksiyonunu bu bardan yönetin.</span>
</div>
<div class="pd-bottom-action-buttons">
    <a href="{{ route('admin.super.product-data-hub.standard-products.index') }}" class="pd-btn pd-btn-light">Standart Ürün Havuzu</a>
    <a href="{{ route('admin.super.tenant-supplier-access.index') }}" class="pd-btn pd-btn-light">Tenant Erişimleri</a>
    <form action="{{ route('admin.catalog.project') }}" method="POST">
        @csrf
        <button type="submit" class="pd-btn pd-btn-primary">Projeksiyonu Güncelle</button>
    </form>
    <a href="{{ route('admin.catalog.index') }}" class="pd-btn pd-btn-warning">Gelişmiş Ürün ve Katalog</a>
</div>
@endsection
