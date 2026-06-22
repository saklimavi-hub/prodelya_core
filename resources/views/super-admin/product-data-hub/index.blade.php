@extends('layouts.prodelya-admin')

@section('title', 'Product Data Hub')
@section('page_topbar_hidden', '1')

@section('page_actions')
@endsection

@section('content')
@php
    $metricCards = [
        ['label' => 'Global kaynak', 'value' => $platformStats['global_sources'], 'note' => 'Merkezi tanımlı tedarikçi akışları', 'class' => 'pd-metric-card-soft-blue'],
        ['label' => 'Aktif tenant', 'value' => $tenants->count(), 'note' => 'Erişim yönetimi yapılan tenant sayısı', 'class' => 'pd-metric-card-soft-green'],
        ['label' => 'Tenant erişimi', 'value' => $platformStats['total_access'], 'note' => 'Açık tedarikçi erişim kaydı', 'class' => 'pd-metric-card-soft-purple'],
        ['label' => 'Export izni', 'value' => $platformStats['export_addons'], 'note' => 'Export modülü açık tenant', 'class' => 'pd-metric-card-soft-slate'],
        ['label' => 'Limit uyarısı', 'value' => $platformStats['feed_limit_warnings'], 'note' => 'Takip edilmesi gereken feed limiti', 'class' => 'pd-metric-card-soft-amber'],
        ['label' => 'Kalıcı kategori', 'value' => $platformStats['permanent_categories'], 'note' => 'Yeni Prodelya kategori omurgası', 'class' => 'pd-metric-card-soft-green'],
        ['label' => 'Arşiv kategori', 'value' => $platformStats['archived_categories'], 'note' => 'Eski ağaçtan gizlenen kayıtlar', 'class' => 'pd-metric-card-soft-slate'],
        ['label' => 'Yeniden eşleme', 'value' => $platformStats['pending_category_mappings'], 'note' => 'Yeni ağaca bağlanacak tedarikçi kategori', 'class' => 'pd-metric-card-soft-amber'],
        ['label' => 'Kategori bekleyen ürün', 'value' => $platformStats['pending_standard_product_categories'], 'note' => 'Ortak ürün havuzunda eşleme bekleyen', 'class' => 'pd-metric-card-soft-purple'],
        ['label' => 'Tenant bekleyen', 'value' => $platformStats['pending_tenant_catalog_categories'], 'note' => 'Tenant katalogda kategori bekleyen', 'class' => 'pd-metric-card-soft-blue'],
    ];
    $packageRules = [
        ['name' => 'Local / Kendi Ürünleri', 'starter' => true, 'promotion' => true, 'suite' => true, 'enterprise' => true, 'scope' => 'Herkes', 'scope_tone' => 'pd-badge-blue'],
        ['name' => 'Global Tedarikçi Feed', 'starter' => false, 'promotion' => true, 'suite' => true, 'enterprise' => true, 'scope' => 'Promotion+', 'scope_tone' => 'pd-badge-amber'],
        ['name' => 'Gelişmiş Ürün ve Katalog', 'starter' => false, 'promotion' => false, 'suite' => true, 'enterprise' => true, 'scope' => 'Suite+', 'scope_tone' => 'pd-badge-purple'],
        ['name' => 'Export / Web Feed', 'starter' => false, 'promotion' => false, 'suite' => false, 'enterprise' => true, 'scope' => 'Enterprise', 'scope_tone' => 'pd-badge-green'],
    ];
@endphp

<div class="pd-page-shell">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Product Data Hub</h1>
                    <p class="pd-hero-subtitle">Tedarikçi kaynaklarını normalize edin, ortak ürün havuzuna dönüştürün ve tenant katalog, teklif, sipariş ile export çıkışlarını aynı merkezden yönetin.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">Super Admin</span>
                        <span class="pd-badge pd-badge-green">Ortak Ürün Havuzu</span>
                        <span class="pd-badge pd-badge-purple">Tenant Çıkışları</span>
                        <span class="pd-badge pd-badge-amber">Teklif / Sipariş / XML Export</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.product-data-hub.sources.create') }}" class="pd-btn pd-btn-primary">Yeni Kaynak</a>
                    <a href="{{ route('admin.super.product-data-hub.product-panel') }}" class="pd-btn pd-btn-primary">Ürün Paneli</a>
                    <a href="{{ route('admin.super.product-data-hub.common-products') }}" class="pd-btn pd-btn-light">Teknik Detaylı Görünüm</a>
                    <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-light">Tenant Çıkışları</a>
                    <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-btn pd-btn-warning">Kategori Eşleme</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-metric-grid">
        @foreach($metricCards as $metric)
            <div class="pd-metric-card {{ $metric['class'] }}">
                <div class="pd-metric-card-label">{{ $metric['label'] }}</div>
                <div class="pd-metric-card-value">{{ $metric['value'] }}</div>
                <div class="pd-metric-card-note">{{ $metric['note'] }}</div>
            </div>
        @endforeach
    </section>

    <section class="pd-section-card pd-section-card-soft-amber">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Kategori Reset Durumu</h3>
                <p class="pd-section-subtitle">Yeni kalıcı kategori omurgası aktiftir. Tedarikçi kategorileri bu omurgaya yeniden eşlenmelidir.</p>
            </div>
            <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-btn pd-btn-light pd-btn-sm">Standart Kategori Ağacı</a>
        </div>
        <div class="pd-section-body">
            <div class="pd-summary-list">
                <span class="pd-summary-item">Kalıcı kategori: {{ $platformStats['permanent_categories'] }}</span>
                <span class="pd-summary-item">Arşiv kategori: {{ $platformStats['archived_categories'] }}</span>
                <span class="pd-summary-item">Yeniden eşleme bekleyen: {{ $platformStats['pending_category_mappings'] }}</span>
                <span class="pd-summary-item">Son backup: {{ $platformStats['last_category_reset_backup'] ?: 'Henüz yok' }}</span>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Product Data Hub Akışı</h3>
                <p class="pd-section-subtitle">Tedarikçi kaynaklarından normalize staging, ortak ürün havuzu, kategori eşleme ve tenant çıkışlarına kadar tüm veri hattını adım adım izleyin.</p>
            </div>
            <a href="{{ route('admin.super.product-data-hub.pipeline') }}" class="pd-btn pd-btn-light pd-btn-sm">Akış Kontrol</a>
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

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Tedarikçi Kaynakları</h3>
                <p class="pd-section-subtitle">Merkezden yönetilen veri kaynakları ve tenant kapsamları.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-source-list">
                @foreach($globalSources as $source)
                    <div class="pd-source-row">
                        <div class="pd-source-main">
                            <h4 class="pd-source-name">{{ $source['supplier_name'] }}</h4>
                            <div class="pd-source-subline">
                                <span class="pd-muted-badge">{{ $source['supplier_code'] ?: 'Henüz yok' }}</span>
                                <span class="pd-badge pd-badge-{{ $source['source_type'] === 'xml' ? 'blue' : ($source['source_type'] === 'json' ? 'green' : ($source['source_type'] === 'api' ? 'purple' : 'amber')) }}">{{ strtoupper($source['source_type']) }}</span>
                                <span class="pd-badge pd-badge-{{ $source['global_status'] === 'Aktif' ? 'green' : 'gray' }}">{{ $source['global_status'] }}</span>
                            </div>
                        </div>
                        <div class="pd-source-meta pd-source-meta-grid">
                            <div class="pd-source-meta-line">Alan eşleme: <span class="pd-source-meta-chip">{{ $source['field_mapping'] ?: 'Henüz tanımlı değil' }}</span></div>
                            <div class="pd-source-meta-line">Kategori: <span class="pd-source-meta-chip">{{ $source['category_mapping'] ?: 'Henüz tanımlı değil' }}</span></div>
                            <div class="pd-source-meta-line">Son çekim: <span class="pd-source-meta-chip">{{ $source['last_sync'] ?: '-' }}</span></div>
                            <div class="pd-source-meta-line">Tenant erişimi: <span class="pd-source-meta-chip">{{ $source['tenant_count'] }}</span></div>
                        </div>
                        <div class="pd-source-actions">
                            <a href="{{ route('admin.super.product-data-hub.sources.edit', $source['id']) }}" class="pd-btn pd-btn-light pd-btn-sm">Düzenle</a>
                            <form action="{{ route('admin.super.product-data-hub.sources.test', $source['id']) }}" method="POST">
                                @csrf
                                <button type="submit" class="pd-btn pd-btn-light pd-btn-sm">Test</button>
                            </form>
                            <a href="{{ route('admin.super.product-data-hub.sources.preview', $source['id']) }}" class="pd-btn pd-btn-primary pd-btn-sm">Preview</a>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="pd-main-utility-grid">
        <div class="pd-nav-card pd-nav-card-compact">
            <div class="pd-card-body">
                <h3 class="pd-nav-title">Final Menü Akışı</h3>
                <div class="pd-mini-grid">
                    <div class="pd-mini-grid-heading">GÜNLÜK</div>
                    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-mini-link-card"><div class="pd-mini-link-title">Tedarikçi Kaynakları</div><div class="pd-mini-link-copy">Kaynakları yönetin</div></a>
                    <a href="{{ route('admin.super.product-data-hub.product-panel') }}" class="pd-mini-link-card"><div class="pd-mini-link-title">Ürün Paneli</div><div class="pd-mini-link-copy">Ürünleri hızlı listeleyin, filtreleyin ve kategori/stok/fiyat durumunu kontrol edin.</div></a>
                    <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-mini-link-card"><div class="pd-mini-link-title">Kategori Eşleme</div><div class="pd-mini-link-copy">Bekleyenleri bağlayın</div></a>
                    <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-mini-link-card"><div class="pd-mini-link-title">Tenant Çıkışları</div><div class="pd-mini-link-copy">Katalog ve export akışını görün</div></a>
                    <a href="{{ route('admin.super.product-data-hub.sources.sync-reports') }}" class="pd-mini-link-card"><div class="pd-mini-link-title">Senkron ve Raporlar</div><div class="pd-mini-link-copy">Run ve kalite uyarılarını izleyin</div></a>
                    <div class="pd-mini-grid-heading">KATEGORİ</div>
                    <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-mini-link-card"><div class="pd-mini-link-title">Standart Kategoriler</div><div class="pd-mini-link-copy">Hedef ağacı açın</div></a>
                    <a href="{{ route('admin.super.product-data-hub.category-cleanup.index') }}" class="pd-mini-link-card"><div class="pd-mini-link-title">Kategori Temizlik</div><div class="pd-mini-link-copy">Duplicate ve alias adayları</div></a>
                    <a href="{{ route('admin.super.product-data-hub.category-feature-templates.index') }}" class="pd-mini-link-card"><div class="pd-mini-link-title">Özellik Şablonları</div><div class="pd-mini-link-copy">Filtre ve export alanları</div></a>
                    <a href="{{ route('admin.super.product-data-hub.category-review-batches.show', '001') }}" class="pd-mini-link-card"><div class="pd-mini-link-title">Review Paketleri</div><div class="pd-mini-link-copy">Kullanıcı karar ekranı</div></a>
                    <div class="pd-mini-grid-heading">ARAÇLAR / BAKIM</div>
                    <a href="{{ route('admin.super.tenant-supplier-access.index') }}" class="pd-mini-link-card"><div class="pd-mini-link-title">Tenant Erişimleri</div><div class="pd-mini-link-copy">Yetkileri yönetin</div></a>
                    <a href="{{ route('admin.super.product-data-hub.pipeline') }}" class="pd-mini-link-card"><div class="pd-mini-link-title">Akış Kontrol</div><div class="pd-mini-link-copy">Veri hattını izleyin</div></a>
                    <a href="{{ route('admin.super.product-data-hub.profile-comparison') }}" class="pd-mini-link-card"><div class="pd-mini-link-title">Profil Karşılaştırma</div><div class="pd-mini-link-copy">Tedarikçi farklarını görün</div></a>
                    <a href="{{ route('admin.super.product-data-hub.common-products') }}" class="pd-mini-link-card"><div class="pd-mini-link-title">Ortak Ürün Havuzu</div><div class="pd-mini-link-copy">Normalize edilmiş teknik ürün detaylarını ve standart ürün kayıtlarını inceleyin.</div></a>
                    <a href="{{ route('admin.super.product-data-hub.standard-products.index') }}" class="pd-mini-link-card"><div class="pd-mini-link-title">Standart Ürünler</div><div class="pd-mini-link-copy">Teknik listeyi açın</div></a>
                </div>
            </div>
        </div>

        <div class="pd-section-card pd-section-card-soft-blue pd-model-card">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">İki Katmanlı Yönetim Modeli</h3>
                    <p class="pd-section-subtitle">Global motor ve tenant kullanım sınırlarını tek bakışta görün.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-compact-flow-grid">
                    <div class="pd-compact-flow-card pd-flow-card-blue">
                        <span class="pd-badge pd-badge-blue">Global Kaynak</span>
                        <div class="pd-flow-copy">Kaynaklar merkezden tanımlanır.</div>
                    </div>
                    <div class="pd-compact-flow-card pd-flow-card-green">
                        <span class="pd-badge pd-badge-green">Ortak Ürün Havuzu</span>
                        <div class="pd-flow-copy">Normalize edilen tek ürün dili burada oluşur.</div>
                    </div>
                    <div class="pd-compact-flow-card pd-flow-card-amber">
                        <span class="pd-badge pd-badge-amber">Tenant Erişimi</span>
                        <div class="pd-flow-copy">Yetki ve limitler tenant bazlı açılır.</div>
                    </div>
                    <div class="pd-compact-flow-card pd-flow-card-red">
                        <span class="pd-badge pd-badge-red">Tenant Çıkışı</span>
                        <div class="pd-flow-copy">Tenant yalnız izinli katalog ve teklif ürünlerini görür.</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-green">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Tenant Tedarikçi Erişim Matrisi</h3>
                <p class="pd-section-subtitle">Paket, feed limiti ve export yetkisini tenant bazında denetleyin.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-table-wrap">
                <div class="pd-access-matrix">
                    <div class="pd-access-head">
                        <div class="pd-access-cell pd-access-cell-head">Tenant</div>
                        <div class="pd-access-cell pd-access-cell-head">PD Hub</div>
                        <div class="pd-access-cell pd-access-cell-head">Advanced</div>
                        <div class="pd-access-cell pd-access-cell-head">Feed</div>
                        @foreach($globalSources as $source)
                            <div class="pd-access-cell pd-access-cell-head">{{ $source['supplier_code'] }}</div>
                        @endforeach
                        <div class="pd-access-cell pd-access-cell-head">Export</div>
                        <div class="pd-access-cell pd-access-cell-head">Feed Limit</div>
                    </div>

                    @foreach($accessMatrix as $row)
                        <div class="pd-access-row">
                            <div class="pd-access-cell pd-access-cell-tenant">
                                <div>
                                    <div style="font-weight:700; color:var(--pd-text);">{{ $row['tenant']->name }}</div>
                                    <div class="pd-source-meta-line">{{ $row['tenant']->package_key ?: 'Paket bilgisi yok' }}</div>
                                </div>
                            </div>
                            <div class="pd-access-cell pd-access-cell-soft"><span class="pd-toggle-pill {{ $row['product_data_hub'] ? 'pd-toggle-pill-on' : 'pd-toggle-pill-off' }}">{{ $row['product_data_hub'] ? 'Açık' : 'Kapalı' }}</span></div>
                            <div class="pd-access-cell pd-access-cell-soft"><span class="pd-toggle-pill {{ $row['advanced_catalog'] ? 'pd-toggle-pill-on' : 'pd-toggle-pill-off' }}">{{ $row['advanced_catalog'] ? 'Açık' : 'Kapalı' }}</span></div>
                            <div class="pd-access-cell pd-access-cell-soft"><span class="pd-toggle-pill {{ $row['active'] ? 'pd-toggle-pill-on' : 'pd-toggle-pill-off' }}">{{ $row['active'] ? 'Açık' : 'Kapalı' }}</span></div>
                            @foreach($globalSources as $source)
                                @php($hasAccess = $row['sources'][$source['id']]?->isCurrentlyAccessible())
                                <div class="pd-access-cell {{ $hasAccess ? 'pd-access-cell-green' : 'pd-access-cell-soft' }}">
                                    <span class="pd-toggle-pill {{ $hasAccess ? 'pd-toggle-pill-on' : 'pd-toggle-pill-off' }}">{{ $hasAccess ? 'Açık' : 'Kapalı' }}</span>
                                </div>
                            @endforeach
                            <div class="pd-access-cell pd-access-cell-purple"><span class="pd-toggle-pill {{ $row['export'] ? 'pd-toggle-pill-on' : 'pd-toggle-pill-off' }}">{{ $row['export'] ? 'Açık' : 'Kapalı' }}</span></div>
                            <div class="pd-access-cell pd-access-cell-soft">{{ $row['feed_limit'] === null ? 'Henüz yok' : ($row['feed_limit'] === -1 ? 'Sınırsız' : $row['feed_limit']) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="pd-settings-grid">
        <div class="pd-setting-panel pd-setting-panel-blue">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Super Admin Ayarları</h3>
                    <p class="pd-section-subtitle">Tenant tarafının değiştiremeyeceği global motor ayarları.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-setting-list">
                    <div class="pd-setting-row"><span>Alan Eşleme Şablonları</span><span class="pd-badge pd-badge-green">Aktif</span></div>
                    <div class="pd-setting-row"><span>Kategori Ağacı</span><span class="pd-badge pd-badge-green">Aktif</span></div>
                    <div class="pd-setting-row"><span>Senkron Politikası</span><span class="pd-badge pd-badge-green">Aktif</span></div>
                    <div class="pd-setting-row"><span>Export Modülleri</span><span class="pd-badge pd-badge-green">Aktif</span></div>
                </div>
            </div>
        </div>

        <div class="pd-setting-panel pd-setting-panel-green">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Tenant Yetki Alanları</h3>
                    <p class="pd-section-subtitle">Tenant tarafında açılabilen güvenli satış ve katalog ayarları.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-setting-list">
                    <div class="pd-setting-row"><span>Fiyat Çarpanı</span><span class="pd-badge pd-badge-blue">İzinli</span></div>
                    <div class="pd-setting-row"><span>Güvenli Stok</span><span class="pd-badge pd-badge-blue">İzinli</span></div>
                    <div class="pd-setting-row"><span>Katalogda Görünsün</span><span class="pd-badge pd-badge-blue">İzinli</span></div>
                    <div class="pd-setting-row"><span>Local Ürün Import</span><span class="pd-badge pd-badge-blue">İzinli</span></div>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-purple">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Modül / Paket Kararları</h3>
                <p class="pd-section-subtitle">Paket bazlı capability dağılımını takip edin.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-table-wrap">
                <table class="pd-package-table">
                    <thead>
                        <tr>
                            <th style="text-align:left;">Yetenek</th>
                            <th>Starter</th>
                            <th>Promotion</th>
                            <th>Suite</th>
                            <th>Enterprise</th>
                            <th>Kontrol</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($packageRules as $rule)
                            <tr>
                                <td style="text-align:left;">{{ $rule['name'] }}</td>
                                <td>@if($rule['starter'])<span class="pd-check-dot">✓</span>@else<span class="pd-dash-dot">-</span>@endif</td>
                                <td>@if($rule['promotion'])<span class="pd-check-dot">✓</span>@else<span class="pd-dash-dot">-</span>@endif</td>
                                <td>@if($rule['suite'])<span class="pd-check-dot">✓</span>@else<span class="pd-dash-dot">-</span>@endif</td>
                                <td>@if($rule['enterprise'])<span class="pd-check-dot">✓</span>@else<span class="pd-dash-dot">-</span>@endif</td>
                                <td><span class="pd-badge {{ $rule['scope_tone'] }}">{{ $rule['scope'] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-purple">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Tenant Çıkışları</h3>
                <p class="pd-section-subtitle">Ortak ürün havuzundan tenant katalog projection, Gelişmiş Ürün ve Katalog, teklif araması ve export akışına giden temiz veri hattını izleyin.</p>
            </div>
            <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-primary pd-btn-sm">Tenant Çıkışlarını Aç</a>
        </div>
        <div class="pd-section-body">
            <div class="pd-process-grid">
                <div class="pd-process-card">
                    <div class="pd-process-title">Toplam standart ürün</div>
                    <div class="pd-process-count">{{ $catalogOutput['total_standard_products'] }}</div>
                </div>
                <div class="pd-process-card">
                    <div class="pd-process-title">Toplam varyant</div>
                    <div class="pd-process-count">{{ $catalogOutput['total_variants'] }}</div>
                </div>
                <div class="pd-process-card">
                    <div class="pd-process-title">Tenant'a açık ürün</div>
                    <div class="pd-process-count">{{ $catalogOutput['tenant_open_products'] }}</div>
                </div>
                <div class="pd-process-card">
                    <div class="pd-process-title">Tenant'a kapalı ürün</div>
                    <div class="pd-process-count">{{ $catalogOutput['tenant_closed_products'] }}</div>
                </div>
                <div class="pd-process-card">
                    <div class="pd-process-title">Kategori eksiği</div>
                    <div class="pd-process-count">{{ $catalogOutput['category_missing_products'] }}</div>
                </div>
                <div class="pd-process-card">
                    <div class="pd-process-title">Fallback kategoride</div>
                    <div class="pd-process-count">{{ $catalogOutput['fallback_category_products'] }}</div>
                </div>
                <div class="pd-process-card">
                    <div class="pd-process-title">Bekliyor ama görünür</div>
                    <div class="pd-process-count">{{ $catalogOutput['category_pending_visible_products'] }}</div>
                </div>
                <div class="pd-process-card">
                    <div class="pd-process-title">Kategori bloklu</div>
                    <div class="pd-process-count">{{ $catalogOutput['category_blocked_products'] }}</div>
                </div>
                <div class="pd-process-card">
                    <div class="pd-process-title">Uyarılı ürün</div>
                    <div class="pd-process-count">{{ $catalogOutput['warning_products'] }}</div>
                </div>
            </div>
            <div class="pd-actions" style="margin-top:16px;">
                <a href="{{ route('admin.super.product-data-hub.standard-products.index') }}" class="pd-btn pd-btn-light pd-btn-sm">Standart Ürün Havuzu</a>
                <a href="{{ route('admin.super.tenant-supplier-access.index') }}" class="pd-btn pd-btn-light pd-btn-sm">Tenant Erişimlerini Düzenle</a>
                <a href="{{ route('admin.super.product-data-hub.category-mappings.index', ['queue' => 'pending']) }}" class="pd-btn pd-btn-warning pd-btn-sm">Eksik Kategorili Ürünleri Göster</a>
            </div>
        </div>
    </section>
</div>
@endsection

@section('side_summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Yönetim Özeti</h3>

        <div class="pd-status-list">
            <div class="pd-status-row"><span>Global kaynak</span><span class="pd-badge pd-badge-blue">{{ $summary['global_sources'] }}</span></div>
            <div class="pd-status-row"><span>Aktif tenant</span><span class="pd-badge pd-badge-green">{{ $summary['active_tenants'] }}</span></div>
            <div class="pd-status-row"><span>Tenant erişimi</span><span class="pd-badge pd-badge-purple">{{ $summary['tenant_access'] }}</span></div>
            <div class="pd-status-row"><span>Export izni</span><span class="pd-badge pd-badge-blue">{{ $summary['export_permission'] }}</span></div>
            <div class="pd-status-row"><span>Limit uyarısı</span><span class="pd-badge pd-badge-amber">{{ $summary['limit_warnings'] }}</span></div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Hızlı Aksiyonlar</h4>
            <div class="pd-summary-action-list">
                <a href="{{ route('admin.super.product-data-hub.sources.create') }}" class="pd-summary-action"><span>Yeni Kaynak</span><span class="pd-badge pd-badge-blue">Yeni</span></a>
                <a href="{{ route('admin.super.product-data-hub.common-products') }}" class="pd-summary-action"><span>Ortak Ürün Havuzu</span><span class="pd-badge pd-badge-green">Merkez</span></a>
                <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-summary-action"><span>Kategori Eşleme</span><span class="pd-badge pd-badge-purple">Map</span></a>
                <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-summary-action"><span>Tenant Çıkışları</span><span class="pd-badge pd-badge-amber">Çıkış</span></a>
                <a href="{{ route('admin.super.product-data-hub.pipeline') }}" class="pd-summary-action"><span>Akış Kontrol</span><span class="pd-badge pd-badge-gray">Araç</span></a>
            </div>
        </div>

        <div class="pd-side-note">Tenant global XML/API kaynaklarını değiştiremez. Sadece kendisine açılan tedarikçi ve katalog ayarlarını yönetir.</div>
    </div>
</div>
@endsection

@section('bottom_actions')
<div>
    <strong>Hızlı geçiş:</strong>
    <span class="pd-muted">Global kaynak, akış kontrolü ve kategori eşleme adımlarını yönetin.</span>
</div>
<div class="pd-bottom-action-buttons">
    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Global Kaynaklar</a>
    <a href="{{ route('admin.super.product-data-hub.pipeline') }}" class="pd-btn pd-btn-light">Akış Kontrol Paneli</a>
    <a href="{{ route('admin.super.product-data-hub.profile-comparison') }}" class="pd-btn pd-btn-light">Profil Karşılaştırma</a>
    <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-btn pd-btn-warning">Kategori Eşleme</a>
    <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-primary">Katalog Çıkışı</a>
</div>
@endsection
