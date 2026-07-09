@extends('layouts.prodelya-admin')

@section('title', 'Senkron Sonuç Merkezi')
@section('page_topbar_hidden', '1')

@section('page_actions')
@endsection

@section('content')
@php
    $metricCards = [
        ['label' => 'Aktif Hazır Tedarikçi Kaynağı', 'value' => $platformStats['global_sources'], 'note' => 'Merkezden yönetilen aktif kaynak sayısı', 'class' => 'pd-metric-card-soft-blue'],
        ['label' => 'Kontrol Bekleyen Ürün', 'value' => $platformStats['pending_standard_product_categories'], 'note' => 'Ürün havuzunda kategori kararı bekleyen kayıtlar', 'class' => 'pd-metric-card-soft-purple'],
        ['label' => 'Kategori Bekleyen', 'value' => $platformStats['pending_category_mappings'], 'note' => 'Kategori eşleme kuyruğundaki tedarikçi kayıtları', 'class' => 'pd-metric-card-soft-amber'],
        ['label' => 'Abone Katalog Yayını Bekleyen', 'value' => $platformStats['pending_tenant_catalog_categories'], 'note' => 'Abone Firma kataloğunda kategori kararı bekleyen ürünler', 'class' => 'pd-metric-card-soft-slate'],
        ['label' => 'Abone Firma Erişimi', 'value' => $platformStats['total_access'], 'note' => 'Açık tedarikçi erişim kaydı', 'class' => 'pd-metric-card-soft-green'],
    ];

    $primaryLinks = [
        ['title' => 'Ürünleri Senkronize Et', 'copy' => 'Normalde günlük güncelleme için senkronizasyon yeterlidir; uygun ürünler satış listesine otomatik yansır.', 'href' => route('admin.super.product-data-hub.sources.index')],
        ['title' => 'Bekleyen Kontrolleri Aç', 'copy' => 'Sadece kategori, kimlik, fiyat anomali ve pasif ürün gibi karar gereken işleri görün.', 'href' => route('admin.super.product-data-hub.product-panel', ['flow_mode' => 'review_queue'])],
        ['title' => 'Yeni Kaynak Ekle', 'copy' => 'Yeni tedarikçi kaynağını bağlayın, ön kontrol yapın ve senkronizasyona hazır hale getirin.', 'href' => route('admin.super.product-data-hub.sources.create')],
        ['title' => 'Güncelleme Ayarı', 'copy' => 'Saatlik/günlük otomatik akışın teknik çalışma mantığını ve bakım adımlarını kontrol edin.', 'href' => route('admin.super.product-data-hub.pipeline')],
    ];

    $detailLinks = [
        ['title' => 'Durum Merkezi', 'href' => route('admin.super.product-data-hub.index')],
        ['title' => 'Ürün Paneli', 'href' => route('admin.super.product-data-hub.product-panel')],
        ['title' => 'Standart Ürünler', 'href' => route('admin.super.product-data-hub.standard-products.index')],
        ['title' => 'Standart Kategori Ağacı', 'href' => route('admin.super.standard-categories.index')],
        ['title' => 'Kategori Temizlik', 'href' => route('admin.super.product-data-hub.category-cleanup.index')],
        ['title' => 'Özellik Şablonları', 'href' => route('admin.super.product-data-hub.category-feature-templates.index')],
        ['title' => 'Akış Kontrol', 'href' => route('admin.super.product-data-hub.pipeline')],
        ['title' => 'Abone Firma Erişimleri', 'href' => route('admin.super.tenant-supplier-access.index')],
        ['title' => 'Profil Karşılaştırma', 'href' => route('admin.super.product-data-hub.profile-comparison')],
        ['title' => 'Yeni Kaynak', 'href' => route('admin.super.product-data-hub.sources.create')],
    ];
@endphp

<div class="pd-page-shell pd-product-hub">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Senkron Sonuç Merkezi</h1>
                    <p class="pd-hero-subtitle">Durum Merkezi artık tek soru üstünden ilerler: bugün aksiyon gereken ürün var mı? Normal kullanımda yalnız senkronizasyon yeterlidir; sistem satış listesine otomatik yansıtır, yalnız istisnalar operatöre iş çıkarır.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">Durum Merkezi</span>
                        <span class="pd-badge pd-badge-light">Super Admin</span>
                        <span class="pd-badge pd-badge-light">Otomatik satış listesi</span>
                        <span class="pd-badge pd-badge-green">{{ $platformStats['global_sources'] }} aktif kaynak</span>
                        <span class="pd-badge pd-badge-amber">{{ $platformStats['pending_category_mappings'] }} kategori bekliyor</span>
                        <span class="pd-badge pd-badge-purple">{{ $platformStats['pending_tenant_catalog_categories'] }} yayın bekliyor</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-primary">Ürünleri Senkronize Et</a>
                    <a href="{{ route('admin.super.product-data-hub.product-panel', ['flow_mode' => 'review_queue']) }}" class="pd-btn pd-btn-warning">Bekleyen Kontrolleri Aç</a>
                    <a href="{{ route('admin.super.product-data-hub.sources.create') }}" class="pd-btn pd-btn-light">Yeni Kaynak Ekle</a>
                    <a href="{{ route('admin.super.product-data-hub.pipeline') }}" class="pd-btn pd-btn-light">Güncelleme Ayarı</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">{{ $operationFlow['headline'] }}</h3>
                <p class="pd-section-subtitle">{{ $operationFlow['supporting_note'] }}</p>
            </div>
            <a href="{{ route('admin.super.product-data-hub.product-panel', ['flow_mode' => 'review_queue']) }}" class="pd-btn pd-btn-warning pd-btn-sm">İnceleme Gerekenleri Aç</a>
        </div>
        <div class="pd-section-body">
            <div class="pd-mini-grid pd-mini-grid-compact">
                @foreach($operationFlow['cards'] as $card)
                    <a href="{{ $card['href'] }}" class="pd-mini-link-card pd-operation-link-card pd-operation-link-card-{{ $card['tone'] }}">
                        <div class="pd-inline-wrap-xs pd-gap-bottom-xs">
                            <span class="pd-badge pd-badge-{{ $card['tone'] }}">{{ $card['count'] }}</span>
                            <span class="pd-muted-badge">{{ $card['title'] }}</span>
                        </div>
                        <div class="pd-mini-link-title">{{ $card['title'] }}</div>
                        <div class="pd-mini-link-copy">{{ $card['copy'] }}</div>
                        <div class="pd-operation-link-action">{{ $card['action'] }}</div>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <section class="pd-kpi-strip">
        @foreach($metricCards as $metric)
            <div class="pd-metric-card {{ $metric['class'] }}">
                <div class="pd-metric-card-label">{{ $metric['label'] }}</div>
                <div class="pd-metric-card-value">{{ $metric['value'] }}</div>
                <div class="pd-metric-card-note">{{ $metric['note'] }}</div>
            </div>
        @endforeach
    </section>

    <section class="pd-section-card pd-section-card-soft-slate pd-product-hub__flow">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Sade Günlük Akış</h3>
                    <p class="pd-section-subtitle">Normalde yalnız senkronizasyon yeterlidir. Sistem uygun ürünleri otomatik satış listesine yansıtır; yalnız sorunlu kayıtlar bekleyen kontrole düşer.</p>
                </div>
                <a href="{{ route('admin.super.product-data-hub.pipeline') }}" class="pd-btn pd-btn-light pd-btn-sm">Güncelleme Ayarını Aç</a>
            </div>
        <div class="pd-section-body">
            <div class="pd-mini-grid pd-mini-grid-compact">
                @foreach($primaryLinks as $link)
                    <a href="{{ $link['href'] }}" class="pd-mini-link-card">
                        <div class="pd-mini-link-title">{{ $link['title'] }}</div>
                        <div class="pd-mini-link-copy">{{ $link['copy'] }}</div>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-blue">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Kısa Operasyon Özeti</h3>
                    <p class="pd-section-subtitle">Raw, standard, projection ve tenant çıkışı zincirinin bugün hangi noktada temiz aktığını veya istisna ürettiğini özetler.</p>
                </div>
                <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-primary pd-btn-sm">Kaynakları Aç</a>
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

    <section class="pd-section-card pd-section-card-soft-green">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Hazır Tedarikçi Kaynakları</h3>
                <p class="pd-section-subtitle">En kısa kaynak özeti. Ayrıntılı stepper ve aksiyonlar Tedarikçi Akışları ekranındadır.</p>
            </div>
            <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light pd-btn-sm">Tedarikçi Akışlarını Aç</a>
        </div>
        <div class="pd-section-body">
            <div class="pd-source-list">
                @foreach($globalSources->take(6) as $source)
                    <div class="pd-source-row pd-source-row-compact">
                        <div class="pd-source-main">
                            <h4 class="pd-source-name">{{ $source['supplier_name'] }}</h4>
                            <div class="pd-source-subline">
                                <span class="pd-muted-badge">{{ $source['supplier_code'] ?: 'Kod yok' }}</span>
                                <span class="pd-badge pd-badge-{{ $source['source_type'] === 'xml' ? 'blue' : ($source['source_type'] === 'json' ? 'green' : ($source['source_type'] === 'api' ? 'purple' : 'amber')) }}">{{ strtoupper($source['source_type']) }}</span>
                                <span class="pd-badge pd-badge-{{ $source['global_status'] === 'Aktif' ? 'green' : 'gray' }}">{{ $source['global_status'] }}</span>
                            </div>
                        </div>
                        <div class="pd-source-meta pd-source-meta-grid">
                            <div class="pd-source-meta-line">Alan eşleme: <span class="pd-source-meta-chip">{{ $source['field_mapping'] ?: 'Bekliyor' }}</span></div>
                            <div class="pd-source-meta-line">Kategori: <span class="pd-source-meta-chip">{{ $source['category_mapping'] ?: 'Bekliyor' }}</span></div>
                            <div class="pd-source-meta-line">Abone Firma erişimi: <span class="pd-source-meta-chip">{{ $source['tenant_count'] }}</span></div>
                        </div>
                        <div class="pd-source-actions">
                            <a href="{{ route('admin.super.product-data-hub.sources.preview', $source['id']) }}" class="pd-btn pd-btn-primary pd-btn-sm">Önizle</a>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-purple">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Teknik ve Bakım Geçişleri</h3>
                <p class="pd-section-subtitle">Bu bölüm günlük karar ekranını kalabalıklaştırmadan teknik bakım ekranlarına kısa geçiş sağlar.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-detail-link-grid">
                @foreach($detailLinks as $link)
                    <a href="{{ $link['href'] }}" class="pd-detail-link">{{ $link['title'] }}</a>
                @endforeach
            </div>
        </div>
    </section>
</div>
@endsection

@section('side_summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Günlük Özet</h3>

        <div class="pd-status-list">
            <div class="pd-status-row"><span>Aktif kaynak</span><strong>{{ $platformStats['global_sources'] }}</strong></div>
            <div class="pd-status-row"><span>Kategori bekleyen</span><strong>{{ $platformStats['pending_category_mappings'] }}</strong></div>
            <div class="pd-status-row"><span>Ürün kontrol kuyruğu</span><strong>{{ $platformStats['pending_standard_product_categories'] }}</strong></div>
            <div class="pd-status-row"><span>Abone katalog yayını bekleyen</span><strong>{{ $platformStats['pending_tenant_catalog_categories'] }}</strong></div>
            <div class="pd-status-row"><span>Abone Firma erişimi</span><strong>{{ $platformStats['total_access'] }}</strong></div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Hızlı Geçiş</h4>
            <div class="pd-summary-action-list">
                <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-summary-action"><span>Ürünleri Senkronize Et</span><span class="pd-badge pd-badge-blue">Sync</span></a>
                <a href="{{ route('admin.super.product-data-hub.product-panel', ['flow_mode' => 'review_queue']) }}" class="pd-summary-action"><span>Bekleyen Kontroller</span><span class="pd-badge pd-badge-amber">Kontrol</span></a>
                <a href="{{ route('admin.super.product-data-hub.sources.create') }}" class="pd-summary-action"><span>Yeni Kaynak</span><span class="pd-badge pd-badge-purple">Kaynak</span></a>
                <a href="{{ route('admin.super.product-data-hub.pipeline') }}" class="pd-summary-action"><span>Güncelleme Ayarı</span><span class="pd-badge pd-badge-green">Ayar</span></a>
            </div>
        </div>

        <div class="pd-side-note">Senkron Sonuç Merkezi günlük karar ekranıdır. Havuza aktar, satış listesine aktar veya teklife gönder gibi ayrı işlem hissi vermez; yalnız istisnaları öne çıkarır.</div>
    </div>
</div>
@endsection

@section('bottom_actions')
<div>
    <strong>Günlük kullanım:</strong>
    <span class="pd-muted">Kaynak akışını, kategori kuyruğunu ve Abone Firma katalog yayını bekleyen işleri bu ekrandan yönetin.</span>
</div>
<div class="pd-bottom-action-buttons">
    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-primary">Tedarikçi Akışları</a>
    <a href="{{ route('admin.super.product-data-hub.pipeline') }}" class="pd-btn pd-btn-light">Akış Kontrol</a>
    <a href="{{ route('admin.super.product-data-hub.category-mappings.index', ['queue' => 'pending']) }}" class="pd-btn pd-btn-warning">Kategori Bekleyenler</a>
    <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-light">Abone Katalog Yayını</a>
</div>
@endsection
