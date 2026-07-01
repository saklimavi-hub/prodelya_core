@extends('layouts.prodelya-admin')

@section('title', 'Akış Kontrol')
@section('page_title', 'Akış Kontrol')
@section('page_subtitle', 'Product Hub veri hattının teknik bakım ve süreç açıklama ekranı.')

@section('page_actions')
<div class="pd-hero-actions">
    <a href="{{ route('admin.super.product-data-hub.index') }}" class="pd-btn pd-btn-light">Durum Merkezi</a>
    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Tedarikçi Akışları</a>
    <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-light">Abone Katalog Yayını</a>
    <a href="{{ route('admin.super.product-data-hub.profile-comparison') }}" class="pd-btn pd-btn-light">Profil Karşılaştırma</a>
</div>
@endsection

@section('content')
<div class="pd-hub-family-shell">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Akış Kontrol</h1>
                    <p class="pd-hero-subtitle">Bu ekran günlük karar ekranı değildir. Kaynak yapısı, süreç sırası ve teknik bakım görünümü için kullanılır.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">Teknik bakım</span>
                        <span class="pd-badge pd-badge-green">{{ $summary['active_sources'] ?? 0 }} aktif kaynak</span>
                        <span class="pd-badge pd-badge-amber">{{ $summary['field_mapping_missing'] ?? 0 }} alan eşleme eksik</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.product-data-hub.index') }}" class="pd-btn pd-btn-primary">Durum Merkezine Dön</a>
                    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Tedarikçi Akışlarını Aç</a>
                    <a href="{{ route('admin.super.standard-categories.index') }}" class="pd-btn pd-btn-light">Standart Kategori Ağacı</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Teknik Akış Özeti</h3>
                <p class="pd-section-subtitle">Hangi adımın hazır, uyarıda veya eksik olduğunu teknik süreç gözüyle takip edin.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-process-grid">
                @foreach($steps as $step)
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

    <div class="pd-mini-kpi-strip mb-6">
        <div class="pd-card">
            <div class="pd-card-header">
                <h3 class="pd-card-title">Super Admin Teknik Kapsamı</h3>
                <p class="pd-card-subtitle">Merkezi kaynak, eşleme ve veri hattı bakımı burada yürütülür.</p>
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
                <h3 class="pd-card-title">Abone Firma Kullanım Sınırı</h3>
                <p class="pd-card-subtitle">Abone Firma tarafı yalnız kendisine açılan katalog ve satış yüzeylerini kullanır.</p>
            </div>
            <div class="pd-card-body">
                <div class="pd-summary-list">
                    @foreach($tenantUsage as $item)
                        <div class="pd-summary-item">{{ str_replace('Tenant', 'Abone Firma', $item) }}</div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Kaynak Hazırlık Sırası</h3>
                <p class="pd-section-subtitle">Yeni kaynak eklerken teknik hazırlık adımlarını kısa sırayla izleyin.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-detail-list-grid">
                @foreach($sourceOnboardingSteps as $index => $step)
                    <div class="pd-detail-link">
                        <strong>{{ $index + 1 }}.</strong> {{ $step }}
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-green">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Kaynak ve Katalog Durumu</h3>
                <p class="pd-section-subtitle">Her tedarikçi için kaynak, eşleme, ürün havuzu ve Abone Firma kataloğu seviyesini takip edin.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Tedarikçi</th>
                            <th>Kaynak</th>
                            <th>Önizleme</th>
                            <th>Alan Eşleme</th>
                            <th>Kategori</th>
                            <th>Ürün Havuzu</th>
                            <th>Abone Katalog</th>
                            <th>Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($supplierPipelineRows as $row)
                            <tr>
                                <td><strong>{{ $row['supplier']->name }}</strong><div class="pd-note">{{ $row['supplier']->code }}</div></td>
                                <td>{{ $row['source_count'] }}</td>
                                <td>{{ $row['preview_records'] }}</td>
                                <td><span class="pd-badge pd-badge-{{ $row['field_mapping_status'] === 'Hazır' ? 'green' : 'amber' }}">{{ $row['field_mapping_status'] }}</span></td>
                                <td><span class="pd-badge pd-badge-{{ $row['category_mapping_status'] === 'Hazır' ? 'green' : 'amber' }}">{{ $row['category_mapping_status'] }}</span></td>
                                <td>{{ $row['standard_products'] }}</td>
                                <td>{{ $row['tenant_catalog_products'] }}</td>
                                <td>
                                    <div class="pd-row-actions">
                                        <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light pd-btn-sm">Kaynakları Aç</a>
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
        <h3 class="pd-summary-title">Teknik Özet</h3>

        <div class="pd-status-list">
            <div class="pd-status-row"><span>Aktif kaynak</span><strong>{{ $summary['active_sources'] }}</strong></div>
            <div class="pd-status-row"><span>Önizleme denemesi</span><strong>{{ $summary['preview_attempts'] }}</strong></div>
            <div class="pd-status-row"><span>Hazırlık kaydı</span><strong>{{ $summary['raw_products'] }}</strong></div>
            <div class="pd-status-row"><span>Ürün havuzu</span><strong>{{ $summary['standard_products'] }}</strong></div>
            <div class="pd-status-row"><span>Abone katalog ürünü</span><strong>{{ $summary['tenant_catalog_products'] }}</strong></div>
            <div class="pd-status-row"><span>Alan eşleme eksik</span><strong>{{ $summary['field_mapping_missing'] }}</strong></div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Teknik Geçişler</h4>
            <div class="pd-summary-action-list">
                <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-summary-action"><span>Tedarikçi Akışları</span><span class="pd-badge pd-badge-blue">Akış</span></a>
                <a href="{{ route('admin.super.product-data-hub.field-mappings.index') }}" class="pd-summary-action"><span>Alan Eşleme</span><span class="pd-badge pd-badge-amber">Eşleme</span></a>
                <a href="{{ route('admin.super.product-data-hub.category-mappings.index') }}" class="pd-summary-action"><span>Kategori Eşleme</span><span class="pd-badge pd-badge-purple">Kategori</span></a>
                <a href="{{ route('admin.super.tenant-supplier-access.index') }}" class="pd-summary-action"><span>Abone Firma Erişimleri</span><span class="pd-badge pd-badge-green">Yetki</span></a>
            </div>
        </div>

        <div class="pd-side-note">Bu ekran süreç açıklama ve teknik bakım ekranıdır. Günlük iş takibi için Durum Merkezi ve Tedarikçi Akışları kullanılmalıdır.</div>
    </div>
</div>
@endsection

@section('bottom_actions')
<div>
    <strong>Teknik kullanım:</strong>
    <span class="pd-muted">Kaynak hazırlığı, eşleme ve veri hattı seviyelerini kısa tablolarla kontrol edin.</span>
</div>
<div class="pd-bottom-action-buttons">
    <a href="{{ route('admin.super.product-data-hub.index') }}" class="pd-btn pd-btn-primary">Durum Merkezi</a>
    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Tedarikçi Akışları</a>
    <a href="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-btn pd-btn-light">Abone Katalog Yayını</a>
</div>
@endsection
