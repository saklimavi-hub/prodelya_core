@extends('layouts.prodelya-admin')

@section('title', 'Product Data Hub Akış Kontrol Paneli')
@section('page_title', 'Product Data Hub Akış Kontrol Paneli')
@section('page_subtitle', 'Global tedarikçi kaynaklarından tenant satış kataloğuna kadar tüm veri akışını buradan takip edin.')

@section('page_actions')
<div class="pd-actions">
    <a href="{{ route('admin.super.product-data-hub.index') }}" class="pd-btn pd-btn-light">Product Data Hub Ana Ekranı</a>
    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Global Kaynaklar</a>
    <a href="{{ route('admin.super.product-data-hub.profile-comparison') }}" class="pd-btn pd-btn-light">Tedarikçi Profil Karşılaştırma</a>
    <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-light">Katalog Çıkışı</a>
    <a href="{{ route('admin.super.tenant-supplier-access.index') }}" class="pd-btn pd-btn-light">Tenant Erişimleri</a>
    <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-btn pd-btn-primary">Standart Kategori Ağacı</a>
</div>
@endsection

@section('content')
<div class="pd-hub-family-shell">
<section class="pd-hero-card">
    <div class="pd-card-body">
        <div class="pd-hero-main">
            <div class="pd-hero-copy">
                <h1 class="pd-hero-title">Product Data Hub Akış Kontrol Paneli</h1>
                <p class="pd-hero-subtitle">Global tedarikçi kaynaklarından tenant satış kataloğuna kadar tüm veri akışını daha kompakt kartlarla takip edin.</p>
                <div class="pd-hero-badges">
                    <span class="pd-badge pd-badge-blue">Akış izleme</span>
                    <span class="pd-badge pd-badge-green">{{ $summary['active_sources'] ?? 0 }} aktif kaynak</span>
                    <span class="pd-badge pd-badge-amber">{{ $summary['field_mapping_missing'] ?? 0 }} alan eşleme eksik</span>
                </div>
            </div>
            <div class="pd-hero-actions">
                <a href="{{ route('admin.super.product-data-hub.index') }}" class="pd-btn pd-btn-light">PD Hub Ana</a>
                <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Global Kaynaklar</a>
                <a href="{{ route('admin.super.product-data-hub.profile-comparison') }}" class="pd-btn pd-btn-light">Profil Karşılaştırma</a>
                <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-btn pd-btn-primary">Standart Kategori Ağacı</a>
            </div>
        </div>
    </div>
</section>

<div class="pd-card pd-section-card mb-6">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Genel Akış Özeti</h3>
        <p class="pd-card-subtitle">Hangi adımın tamamlandığını, hangi adımın beklediğini ve hangi ekrana gitmeniz gerektiğini tek bakışta görün.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-grid pd-grid-3">
            @foreach($steps as $step)
                <div class="pd-card">
                    <div class="pd-card-body">
                        <div class="pd-summary-row">
                            <strong>{{ $step['title'] }}</strong>
                            <span class="pd-badge pd-badge-{{ $step['status'] }}">{{ $step['status_label'] }}</span>
                        </div>
                        <div class="pd-metric" style="margin-top: 8px;">{{ $step['count'] }}</div>
                        <div class="pd-note" style="margin-top: 8px;">Kayıt sayısı</div>
                        <div class="mt-3">
                            <a href="{{ $step['action_route'] }}" class="pd-btn pd-btn-light pd-btn-sm">{{ $step['action_label'] }}</a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>

<div class="pd-grid pd-grid-2 mb-6">
    <div class="pd-card">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Super Admin Yönetim Alanı</h3>
        </div>
        <div class="pd-card-body">
            <div class="pd-summary-list">
                @foreach($superAdminActions as $action)
                    <div class="pd-summary-item">{{ $action }}</div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="pd-card">
        <div class="pd-card-header">
            <h3 class="pd-card-title">Tenant Kullanım Alanı</h3>
        </div>
        <div class="pd-card-body">
            <div class="pd-summary-list">
                @foreach($tenantUsage as $item)
                    <div class="pd-summary-item">{{ $item }}</div>
                @endforeach
            </div>
        </div>
    </div>
</div>

<div class="pd-card pd-section-card mb-6">
    <div class="pd-card-header">
        <h3 class="pd-card-title">XML / JSON / CSV Kaynağı Nasıl Eklenir?</h3>
    </div>
    <div class="pd-card-body">
        <div class="pd-grid pd-grid-2">
            @foreach($sourceOnboardingSteps as $index => $step)
                <div class="pd-summary-row">
                    <span class="pd-badge pd-badge-blue">{{ $index + 1 }}</span>
                    <span>{{ $step }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>

<div class="pd-card pd-section-card mb-6">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Ürün Görselleri ve Varyasyon Görselleri</h3>
        <p class="pd-card-subtitle">Tedarikçi XML’lerinde birden fazla görsel veya varyasyon görseli olabilir.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-summary-list">
            @foreach($imageRules as $rule)
                <div class="pd-summary-item">{{ $rule }}</div>
            @endforeach
        </div>
    </div>
</div>

<div class="pd-card pd-section-card mb-6">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Tenant Erişim Matrisi</h3>
        <p class="pd-card-subtitle">Tenant bazında Product Data Hub, katalog ve tedarikçi erişimlerini özetleyin.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Tenant</th>
                        <th>Product Data Hub</th>
                        <th>Gelişmiş Ürün ve Katalog</th>
                        <th>Etkin</th>
                        <th>Akdeniz</th>
                        <th>İlpen</th>
                        <th>Yeni Nesil</th>
                        <th>Export İzni</th>
                        <th>Feed Limiti</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($accessMatrix as $row)
                        @php
                            $supplierStates = collect($globalSources)
                                ->groupBy('supplier_code')
                                ->map(function ($sources) use ($row) {
                                    return $sources->contains(function ($source) use ($row) {
                                        return $row['sources'][$source['id']]?->isCurrentlyAccessible();
                                    });
                                });
                        @endphp
                        <tr>
                            <td><strong>{{ $row['tenant']->name }}</strong><div class="pd-note">{{ $row['tenant']->package_key ?: '-' }}</div></td>
                            <td><span class="pd-badge pd-badge-{{ $row['product_data_hub'] ? 'green' : 'gray' }}">{{ $row['product_data_hub'] ? 'Açık' : 'Kapalı' }}</span></td>
                            <td><span class="pd-badge pd-badge-{{ $row['advanced_catalog'] ? 'green' : 'gray' }}">{{ $row['advanced_catalog'] ? 'Açık' : 'Kapalı' }}</span></td>
                            <td><span class="pd-badge pd-badge-{{ $supplierStates->get('ETKIN', false) ? 'green' : 'gray' }}">{{ $supplierStates->get('ETKIN', false) ? 'Açık' : 'Kapalı' }}</span></td>
                            <td><span class="pd-badge pd-badge-{{ $supplierStates->get('AKDENIZ', false) ? 'green' : 'gray' }}">{{ $supplierStates->get('AKDENIZ', false) ? 'Açık' : 'Kapalı' }}</span></td>
                            <td><span class="pd-badge pd-badge-{{ $supplierStates->get('ILPEN', false) ? 'green' : 'gray' }}">{{ $supplierStates->get('ILPEN', false) ? 'Açık' : 'Kapalı' }}</span></td>
                            <td><span class="pd-badge pd-badge-{{ $supplierStates->get('YENI-NESIL', false) ? 'green' : 'gray' }}">{{ $supplierStates->get('YENI-NESIL', false) ? 'Açık' : 'Kapalı' }}</span></td>
                            <td><span class="pd-badge pd-badge-{{ $row['export'] ? 'green' : 'gray' }}">{{ $row['export'] ? 'Açık' : 'Kapalı' }}</span></td>
                            <td>{{ $row['feed_limit'] === null ? '-' : ($row['feed_limit'] === -1 ? 'Sınırsız' : $row['feed_limit']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="pd-card pd-section-card">
    <div class="pd-card-header">
        <h3 class="pd-card-title">Veri Hattı Durum Tablosu</h3>
        <p class="pd-card-subtitle">Her tedarikçi için kaynak, preview, mapping, staging ve katalog seviyesini izleyin.</p>
    </div>
    <div class="pd-card-body">
        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Tedarikçi</th>
                        <th>Kaynak Sayısı</th>
                        <th>Son Test</th>
                        <th>Preview Kayıt</th>
                        <th>Field Mapping</th>
                        <th>Kategori Mapping</th>
                        <th>Ham Ürün</th>
                        <th>Standart Ürün</th>
                        <th>Tenant Katalog</th>
                        <th>Son Hata</th>
                        <th>Aksiyon</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($supplierPipelineRows as $row)
                        <tr>
                            <td><strong>{{ $row['supplier']->name }}</strong><div class="pd-note">{{ $row['supplier']->code }}</div></td>
                            <td>{{ $row['source_count'] }}</td>
                            <td>{{ $row['last_test'] }}</td>
                            <td>{{ $row['preview_records'] }}</td>
                            <td><span class="pd-badge pd-badge-{{ $row['field_mapping_status'] === 'Hazır' ? 'green' : 'amber' }}">{{ $row['field_mapping_status'] }}</span></td>
                            <td><span class="pd-badge pd-badge-{{ $row['category_mapping_status'] === 'Hazır' ? 'green' : 'amber' }}">{{ $row['category_mapping_status'] }}</span></td>
                            <td>{{ $row['raw_products'] }}</td>
                            <td>{{ $row['standard_products'] }}</td>
                            <td>{{ $row['tenant_catalog_products'] }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($row['last_error'], 48) }}</td>
                            <td>
                                <div class="pd-actions">
                                    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light pd-btn-sm">Kaynakları Aç</a>
                                    <a href="{{ route('admin.super.tenant-supplier-access.index') }}" class="pd-btn pd-btn-light pd-btn-sm">Tenant Erişimi</a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>
@endsection

@section('side_summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Akış Özeti</h3>

        <div class="pd-status-list">
            <div class="pd-status-row"><span>Global kaynak</span><strong>{{ $summary['global_sources'] }}</strong></div>
            <div class="pd-status-row"><span>Aktif kaynak</span><strong>{{ $summary['active_sources'] }}</strong></div>
            <div class="pd-status-row"><span>Preview alınan</span><strong>{{ $summary['preview_attempts'] }}</strong></div>
            <div class="pd-status-row"><span>Ham ürün</span><strong>{{ $summary['raw_products'] }}</strong></div>
            <div class="pd-status-row"><span>Standart ürün</span><strong>{{ $summary['standard_products'] }}</strong></div>
            <div class="pd-status-row"><span>Tenant katalog ürünü</span><strong>{{ $summary['tenant_catalog_products'] }}</strong></div>
            <div class="pd-status-row"><span>Kategori eşleme bekleyen</span><strong>{{ $summary['category_mapping_pending'] }}</strong></div>
            <div class="pd-status-row"><span>Alan eşleme eksik</span><strong>{{ $summary['field_mapping_missing'] }}</strong></div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Hızlı Aksiyonlar</h4>
            <div class="pd-summary-action-list">
                <a href="{{ route('admin.super.product-data-hub.sources.create') }}" class="pd-summary-action"><span>Global Kaynak Ekle</span><span class="pd-badge pd-badge-blue">Yeni</span></a>
                <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-summary-action"><span>Kaynakları Aç</span><span class="pd-badge pd-badge-green">Liste</span></a>
                <a href="{{ route('admin.super.product-data-hub.profile-comparison') }}" class="pd-summary-action"><span>Profil Karşılaştırma</span><span class="pd-badge pd-badge-purple">Kontrol</span></a>
                <a href="{{ route('admin.super.product-data-hub.field-mappings.index') }}" class="pd-summary-action"><span>Alan Eşleme</span><span class="pd-badge pd-badge-amber">Eşleme</span></a>
                <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-summary-action"><span>Kategori Eşleme</span><span class="pd-badge pd-badge-amber">Kategori</span></a>
                <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-summary-action"><span>Standart Kategori Ağacı</span><span class="pd-badge pd-badge-blue">Ağaç</span></a>
                <a href="{{ route('admin.super.tenant-supplier-access.index') }}" class="pd-summary-action"><span>Tenant Erişimleri</span><span class="pd-badge pd-badge-green">Yetki</span></a>
                <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-summary-action"><span>Katalog Çıkışı</span><span class="pd-badge pd-badge-gray">Tenant</span></a>
            </div>
        </div>

        <div class="pd-side-note">Product Data Hub ana motoru Super Admin kontrolündedir. Tenant tarafı yalnız Gelişmiş Ürün ve Katalog ekranını kullanır.</div>
    </div>
</div>
@endsection

@section('bottom_actions')
<div>
    <strong>Akış notu:</strong>
    <span class="pd-muted">Kaynak, preview, eşleme ve tenant katalog hattını buradan takip edin.</span>
</div>
<div class="pd-bottom-action-buttons">
    <a href="{{ route('admin.super.product-data-hub.index') }}" class="pd-btn pd-btn-light">Product Data Hub Ana</a>
    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Global Kaynaklar</a>
    <a href="{{ route('admin.super.product-data-hub.profile-comparison') }}" class="pd-btn pd-btn-light">Profil Karşılaştırma</a>
    <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-btn pd-btn-warning">Kategori Eşleme</a>
    <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-primary">Katalog Çıkışı</a>
</div>
@endsection
