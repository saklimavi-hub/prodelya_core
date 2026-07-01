@extends('layouts.prodelya-admin')

@section('title', 'Tedarikçi Akışları')
@section('page_title', 'Tedarikçi Akışları')
@section('page_subtitle', 'Sources ekranı artık supplier bazlı genel operasyon listesidir. Günlük karar burada sade özetle görünür; detaylı source işlemleri tedarikçi detayında açılır.')

@section('content')
<div class="pd-hub-family-shell">
    @if (session('success'))
        <div class="pd-note pd-note-soft-blue">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="pd-alert-warning">{{ session('error') }}</div>
    @endif

    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Tedarikçi Akışları</h1>
                    <p class="pd-hero-subtitle">Genel liste supplier bazlıdır. Her tedarikçi için yalnız karar özeti görünür; preview, sync, field mapping ve bakım işlemleri “Detaya Git” ile tedarikçi detayında açılır.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">Super Admin</span>
                        <span class="pd-badge pd-badge-green">{{ $stats['active'] }} aktif kaynak</span>
                        <span class="pd-badge pd-badge-amber">{{ $stats['mapping_missing'] }} alan eşleme kontrolü</span>
                        <span class="pd-badge pd-badge-red">{{ $stats['temp'] }} geçici profil</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.product-data-hub.sources.create') }}" class="pd-btn pd-btn-primary">Yeni Hazır Tedarikçi Kaynağı</a>
                    <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-light">Abone Katalog Yayınını Aç</a>
                    <a href="{{ route('admin.super.product-data-hub.sources.sync-reports') }}" class="pd-btn pd-btn-light">Senkron Raporlarını Aç</a>
                    <a href="{{ route('admin.super.product-data-hub.pipeline') }}" class="pd-btn pd-btn-light">Akış Kontrol</a>
                </div>
            </div>
        </div>
    </section>

    <div class="pd-note">Bu ekran supplier bazlı genel listedir. Günlük operasyon için önce tedarikçiyi seçin; teknik source akışı ve mutating işlemler tedarikçi detayında açılır.</div>

    <section class="pd-kpi-strip">
        <div class="pd-metric-card pd-metric-card-soft-blue"><div class="pd-metric-card-label">Toplam Kaynak</div><div class="pd-metric-card-value">{{ $stats['total'] }}</div><div class="pd-metric-card-note">{{ $suppliers->count() }} tedarikçide toplanıyor</div></div>
        <div class="pd-metric-card pd-metric-card-soft-green"><div class="pd-metric-card-label">Aktif Kaynak</div><div class="pd-metric-card-value">{{ $stats['active'] }}</div><div class="pd-metric-card-note">Günlük akışta kullanılan kaynaklar</div></div>
        <div class="pd-metric-card pd-metric-card-soft-slate"><div class="pd-metric-card-label">Hazır Kaynak</div><div class="pd-metric-card-value">{{ $stats['ready'] }}</div><div class="pd-metric-card-note">Alan eşleme ve önizleme için hazır</div></div>
        <div class="pd-metric-card pd-metric-card-soft-amber"><div class="pd-metric-card-label">Bağlantı Bekleyen</div><div class="pd-metric-card-value">{{ $stats['url_missing'] }}</div><div class="pd-metric-card-note">Kaynak bilgisi tamamlanmalı</div></div>
        <div class="pd-metric-card pd-metric-card-soft-red"><div class="pd-metric-card-label">Geçici Profil</div><div class="pd-metric-card-value">{{ $stats['temp'] }}</div><div class="pd-metric-card-note">Gerçek akışta kullanılmamalı</div></div>
    </section>

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Tedarikçi Listesi</h3>
                <p class="pd-section-subtitle">Her satır son sync, review yükü ve tenant/teklif etkisini özetler. Detaylı source ayarları tek tek burada gösterilmez.</p>
            </div>
            <div class="pd-chip-group">
                <a href="{{ route('admin.super.product-data-hub.sources.index', ['filter' => 'active']) }}" class="pd-chip {{ $activeFilter === 'active' ? 'is-active' : '' }}">Aktif</a>
                <a href="{{ route('admin.super.product-data-hub.sources.index', ['filter' => 'inactive']) }}" class="pd-chip {{ $activeFilter === 'inactive' ? 'is-active' : '' }}">Pasif</a>
                <a href="{{ route('admin.super.product-data-hub.sources.index', ['filter' => 'archived']) }}" class="pd-chip {{ $activeFilter === 'archived' ? 'is-active' : '' }}">Arşiv</a>
                <a href="{{ route('admin.super.product-data-hub.sources.index', ['filter' => 'temp']) }}" class="pd-chip {{ $activeFilter === 'temp' ? 'is-active' : '' }}">Geçici</a>
                <a href="{{ route('admin.super.product-data-hub.sources.index', ['filter' => 'all']) }}" class="pd-chip {{ $activeFilter === 'all' ? 'is-active' : '' }}">Tümü</a>
            </div>
        </div>
        <div class="pd-section-body">
            @if($suppliers->count() > 0)
                <div class="pd-source-list">
                    @foreach($suppliers as $summary)
                        @php
                            $supplier = $summary['supplier'];
                            $syncTone = match ($summary['last_sync_status']) {
                                'completed' => 'green',
                                'failed' => 'red',
                                'running' => 'blue',
                                'warning' => 'amber',
                                default => 'light',
                            };
                        @endphp
                        <article class="pd-source-row">
                            <div class="pd-source-header pd-source-header-clean">
                                <div class="pd-source-main">
                                    <div class="pd-source-kicker">Tedarikçi Operasyon Özeti</div>
                                    <h4 class="pd-source-name">{{ $supplier->name }}</h4>
                                    <div class="pd-source-subtitle">{{ $summary['source_count'] }} kaynak / {{ $summary['active_source_count'] }} aktif kaynak</div>
                                    <div class="pd-source-subline">
                                        <span class="pd-muted-badge">{{ $supplier->code }}</span>
                                        <span class="pd-badge pd-badge-{{ $syncTone }}">Son sync: {{ $summary['last_sync_status'] === 'missing' ? 'Henüz yok' : ucfirst($summary['last_sync_status']) }}</span>
                                        @if($summary['has_tenant_impact'])
                                            <span class="pd-badge pd-badge-blue">Tenant etkisi var</span>
                                        @else
                                            <span class="pd-badge pd-badge-light">Tenant etkisi yok</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="pd-source-primary">
                                    <div class="pd-source-next-label">Ana aksiyon</div>
                                    <a href="{{ $summary['detail_href'] }}" class="pd-btn pd-btn-primary">Detaya Git</a>
                                    <div class="pd-source-next-note">XML/JSON ayarları, preview, sync raporları ve bakım işlemleri tedarikçi detayında açılır.</div>
                                </div>
                            </div>

                            <div class="pd-source-summary-grid pd-source-summary-grid-clean">
                                <div class="pd-source-summary-card">
                                    <div class="pd-source-summary-label">Son Sync</div>
                                    <div class="pd-source-summary-value">{{ $summary['last_sync_at'] ? \Illuminate\Support\Carbon::parse($summary['last_sync_at'])->format('d.m H:i') : '-' }}</div>
                                    <div class="pd-source-summary-note">En güncel kaynak çalışması</div>
                                </div>
                                <div class="pd-source-summary-card">
                                    <div class="pd-source-summary-label">Review Bekleyen</div>
                                    <div class="pd-source-summary-value">{{ $summary['review_total'] }}</div>
                                    <div class="pd-source-summary-note">Yeni ürün, kimlik veya risk kuyruğu</div>
                                </div>
                                <div class="pd-source-summary-card">
                                    <div class="pd-source-summary-label">Fiyat/Stok Değişimi</div>
                                    <div class="pd-source-summary-value">{{ $summary['price_stock_delta_total'] }}</div>
                                    <div class="pd-source-summary-note">Son delta raporlarından özet</div>
                                </div>
                                <div class="pd-source-summary-card">
                                    <div class="pd-source-summary-label">Projection Bekleyen</div>
                                    <div class="pd-source-summary-value">{{ $summary['projection_pending'] }}</div>
                                    <div class="pd-source-summary-note">Tenant kataloğa yansıma bekleyen kayıt</div>
                                </div>
                                <div class="pd-source-summary-card">
                                    <div class="pd-source-summary-label">Tenant Katalog Etkisi</div>
                                    <div class="pd-source-summary-value">{{ $summary['tenant_catalog_products'] }}</div>
                                    <div class="pd-source-summary-note">Projection’da görünen ürün</div>
                                </div>
                                <div class="pd-source-summary-card">
                                    <div class="pd-source-summary-label">Teklif Etkisi</div>
                                    <div class="pd-source-summary-value">{{ $summary['quote_visible_total'] }}</div>
                                    <div class="pd-source-summary-note">Teklifte görünür ürün/varyant</div>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="pd-empty-card">
                    <h3 class="pd-empty-card-title">Henüz tedarikçi kaynağı eklenmedi.</h3>
                    <p class="pd-empty-card-copy">Önce global bir tedarikçi kaynağı ekleyin. Ardından detay ekranında preview, sync ve projection akışını yönetebilirsiniz.</p>
                </div>
            @endif
        </div>
    </section>
</div>
@endsection
