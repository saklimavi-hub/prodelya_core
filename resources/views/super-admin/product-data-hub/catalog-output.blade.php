@extends('layouts.prodelya-admin')

@section('title', 'Abone Katalog Yayını')
@section('page_title', 'Abone Katalog Yayını')
@section('page_subtitle', 'Hazır ürünlerin seçili Abone Firma kataloglarına yansıma durumunu ve eksik katalog kayıtlarını buradan izleyin.')

@section('page_actions')
<div class="pd-actions">
    <a href="{{ route('admin.super.product-data-hub.index') }}" class="pd-btn pd-btn-light">Hub Özeti</a>
    <a href="{{ route('admin.super.product-data-hub.standard-products.index') }}" class="pd-btn pd-btn-light">Teknik Standart Ürünler</a>
    <a href="{{ route('admin.super.tenant-supplier-access.index') }}" class="pd-btn pd-btn-light">Abone Firma Tedarikçi Erişimleri</a>
    @if($selectedTenantCatalogUrl)
        <a href="{{ $selectedTenantCatalogUrl }}" class="pd-btn pd-btn-primary">Seçili Abone Firma Kataloğu</a>
    @else
        <span class="pd-btn pd-btn-primary disabled" aria-disabled="true">Abone Firma seçin</span>
    @endif
</div>
@endsection

@section('content')
@php
    $metricCards = [
        ['label' => 'Abone Firma sayısı', 'value' => $catalogOutput['active_tenants'] ?? 0, 'class' => 'pd-metric-card-soft-blue'],
        ['label' => 'Katalogda ürün / varyant', 'value' => ($catalogOutput['tenant_open_products'] ?? 0) . ' / ' . ($catalogOutput['total_variants'] ?? 0), 'class' => 'pd-metric-card-soft-green'],
        ['label' => 'Kataloğa yansımayı bekleyen', 'value' => $catalogOutput['projection_blocked_missing_category'] ?? 0, 'class' => 'pd-metric-card-soft-amber'],
        ['label' => 'Teklifte kullanılabilir', 'value' => $catalogOutput['tenant_open_products'] ?? 0, 'class' => 'pd-metric-card-soft-purple'],
        ['label' => 'İnceleme bekleyen', 'value' => $catalogOutput['warning_products'] ?? 0, 'class' => 'pd-metric-card-soft-red'],
        ['label' => 'Son katalog güncelleme', 'value' => $catalogOutput['last_projection_run_at'] ?? 'Henüz yok', 'class' => 'pd-metric-card-soft-slate'],
    ];
@endphp

<div class="pd-hub-family-shell">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Abone Katalog Yayını</h1>
                    <p class="pd-hero-subtitle">Bu ekran, Product Hub’daki hazır ürünlerin seçili Abone Firma kataloglarına yansıma durumunu izlemek için kullanılır.</p>
                    <div class="pd-hero-badges">
                        <span class="pd-badge pd-badge-blue">Abone Firma Kataloğu</span>
                        <span class="pd-badge pd-badge-green">Katalog Ürünleri</span>
                        <span class="pd-badge pd-badge-purple">Teklifte Kullanım</span>
                    </div>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.product-data-hub.sources.sync-reports') }}" class="pd-btn pd-btn-light">Ön Kontrol Yap</a>
                    <form action="{{ route('admin.super.product-data-hub.catalog-output.project-missing') }}" method="POST" onsubmit="return confirm('Seçili Abone Firma için eksik projection kayıtları tamamlanacak. Devam edilsin mi?');">
                        @csrf
                        <input type="hidden" name="tenant_id" value="{{ $selectedTenant?->id }}">
                        <button type="submit" class="pd-btn pd-btn-warning" @disabled(!$selectedTenant)>Eksik Kayıtları Tamamla</button>
                    </form>
                    <form action="{{ route('admin.super.product-data-hub.catalog-output.project-refresh') }}" method="POST" onsubmit="return confirm('Seçili Abone Firma için değişen projection kayıtları kataloğa yansıtılacak. Devam edilsin mi?');">
                        @csrf
                        <input type="hidden" name="tenant_id" value="{{ $selectedTenant?->id }}">
                        <button type="submit" class="pd-btn pd-btn-primary" @disabled(!$selectedTenant)>Değişen Ürünleri Kataloğa Yansıt</button>
                    </form>
                </div>
            </div>
            <form method="GET" action="{{ route('admin.super.product-data-hub.catalog-output') }}" class="pd-form-shell mt-4">
                <div class="pd-form-grid pd-form-grid-3">
                    <div class="pd-form-field">
                        <label class="pd-label" for="tenant_id">Abone Firma seçin</label>
                        <select name="tenant_id" id="tenant_id" class="pd-select">
                            <option value="">Abone Firma seçin</option>
                            @foreach($tenants as $tenant)
                                <option value="{{ $tenant->id }}" @selected((int) old('tenant_id', request('tenant_id')) === (int) $tenant->id)>{{ $tenant->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pd-form-actions">
                        <button type="submit" class="pd-btn pd-btn-light">Abone Firmayı Aç</button>
                        @if($selectedTenantCatalogUrl)
                            <a href="{{ $selectedTenantCatalogUrl }}" class="pd-btn pd-btn-light">Abone Firma Panelini Aç</a>
                        @endif
                    </div>
                </div>
            </form>
            <div class="pd-note mt-4">Bu ekran seçili Abone Firma kataloğuna yansıtılmış ürünleri ve eksik katalog kayıtlarını gösterir. Ön kontrol read-only çalışır; projection mutasyonları yalnız seçili Abone Firma context’iyle yapılır.</div>
            <div class="pd-note mt-3">
                @if($selectedTenant)
                    Seçili Abone Firma: <strong>{{ $selectedTenant->name }}</strong>. Merkezi panelden gerçek projection güncellemesi bu tenant için çalıştırılır.
                @else
                    Platform yöneticisi olarak merkezi paneldeyseniz önce Abone Firma seçin. Tenant seçilmeden projection güncellemesi çalıştırılmaz.
                @endif
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

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Katalog Güncelleme Adımları</h3>
                <p class="pd-section-subtitle">Günlük karar için gerekli adımları kısa akışta görün. Teknik projection ayrıntıları detay alanında kalır.</p>
            </div>
            <span class="pd-badge pd-badge-blue">Operasyon özeti</span>
        </div>
        <div class="pd-section-body">
            <div class="pd-process-grid">
                @foreach($processSteps as $step)
                    @php
                        $labelMap = [
                            'Kaynakları Aç' => 'Kaynak Güncelleme Kontrolü',
                            'Ham Ürünleri Aç' => 'Ham Kayıtları İncele',
                            'Standart Ürünleri Aç' => 'Hazır Ürünleri Aç',
                            'Tenant Erişimleri Aç' => 'Tedarikçi Erişimlerini Aç',
                            'Tenant Erişimleri' => 'Tedarikçi Erişimlerini Aç',
                            'Tenant Erişimlerini Aç' => 'Tedarikçi Erişimlerini Aç',
                            'Kategori Mapping Aç' => 'Kategori Bekleyenleri Aç',
                            'Tenant Katalog Çıkışını Aç' => 'Katalog Ürünlerini Aç',
                            'Tenant Çıkışları' => 'Abone Katalog Yayını',
                            'Gelişmiş Ürün ve Katalog' => 'Abone Katalog Yayını',
                        ];
                        $mappedActionLabel = $labelMap[$step['action_label']] ?? $step['action_label'];
                        $stepDescription = $step['description'] ?? 'Bu adım günlük katalog yayını kararını destekler.';
                    @endphp
                    <div class="pd-process-card">
                        <div class="pd-process-head">
                            <span class="pd-badge pd-badge-{{ $step['status'] }}">{{ $step['status_label'] }}</span>
                            <span class="pd-process-count">{{ $step['count'] }}</span>
                        </div>
                        <div class="pd-process-title">{{ str_replace('Tenant', 'Abone Firma', $step['title']) }}</div>
                        <div class="pd-profile-note">{{ str_replace('tenant', 'Abone Firma', Str::lower($stepDescription)) }}</div>
                        <a href="{{ $step['action_route'] }}" class="pd-process-link">{{ $mappedActionLabel }}</a>
                    </div>
                @endforeach
            </div>
            <details class="pd-inline-details mt-4">
                <summary>Gelişmiş İşlemler</summary>
                <div class="pd-chip-list mt-3">
                    <form action="{{ route('admin.super.product-data-hub.catalog-output.project-refresh') }}" method="POST" onsubmit="return confirm('Seçili Abone Firma için projection kayıtları güncellenecek. Devam edilsin mi?');">
                        @csrf
                        <input type="hidden" name="tenant_id" value="{{ $selectedTenant?->id }}">
                        <button type="submit" class="pd-btn pd-btn-light pd-btn-sm" @disabled(!$selectedTenant)>Abone Katalog Güncelle</button>
                    </form>
                    @if($selectedTenantCatalogUrl)
                        <a href="{{ $selectedTenantCatalogUrl }}" class="pd-btn pd-btn-light pd-btn-sm">Katalog Ürünleri</a>
                    @endif
                    <a href="{{ route('admin.super.product-data-hub.sources.sync-reports') }}" class="pd-btn pd-btn-light pd-btn-sm">Senkron Raporunu Aç</a>
                    <span class="pd-muted-badge">Mutating projection işlemleri yalnız seçili Abone Firma context’iyle çalışır.</span>
                </div>
            </details>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-purple">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Katalog Yayını Özeti</h3>
                <p class="pd-section-subtitle">Günlük karar için gerekli sinyaller önde tutulur; teknik sayaçlar detay alanına alınır.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-mini-kpi-strip">
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Katalogda ürün</div>
                    <div class="pd-profile-info-value">{{ $catalogOutput['tenant_open_products'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Katalogda kapalı</div>
                    <div class="pd-profile-info-value">{{ $catalogOutput['tenant_closed_products'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Teklifte kullanılabilir</div>
                    <div class="pd-profile-info-value">{{ $catalogOutput['tenant_open_products'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Eksik kayıtları tamamla</div>
                    <div class="pd-profile-info-value">{{ $catalogOutput['projection_blocked_missing_category'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">Değişen ürünleri kataloğa yansıt</div>
                    <div class="pd-profile-info-value">{{ $catalogOutput['projection_updated_products'] }}</div>
                </div>
                <div class="pd-profile-info">
                    <div class="pd-profile-info-label">İnceleme bekleyen</div>
                    <div class="pd-profile-info-value">{{ $catalogOutput['warning_products'] }}</div>
                </div>
            </div>
            <details class="pd-inline-details mt-4">
                <summary>Teknik Sayaçlar</summary>
                <div class="pd-mini-kpi-strip mt-3">
                    <div class="pd-profile-info">
                        <div class="pd-profile-info-label">Tedarikçi ürünleri</div>
                        <div class="pd-profile-info-value">{{ $catalogOutput['supplier_products'] }}</div>
                    </div>
                    <div class="pd-profile-info">
                        <div class="pd-profile-info-label">Kategori eşlemesi tamamlanan</div>
                        <div class="pd-profile-info-value">{{ $catalogOutput['category_mapped_products'] }}</div>
                    </div>
                    <div class="pd-profile-info">
                        <div class="pd-profile-info-label">Fiyatı eksik ürün</div>
                        <div class="pd-profile-info-value">{{ $catalogOutput['missing_price_products'] }}</div>
                    </div>
                    <div class="pd-profile-info">
                        <div class="pd-profile-info-label">Stok değişen</div>
                        <div class="pd-profile-info-value">{{ $catalogOutput['stock_changed_products'] }}</div>
                    </div>
                    <div class="pd-profile-info">
                        <div class="pd-profile-info-label">Fiyat değişen</div>
                        <div class="pd-profile-info-value">{{ $catalogOutput['price_changed_products'] }}</div>
                    </div>
                    <div class="pd-profile-info">
                        <div class="pd-profile-info-label">Uyarıyla çıkan</div>
                        <div class="pd-profile-info-value">{{ $catalogOutput['projection_warning_outputs'] }}</div>
                    </div>
                </div>
            </details>
        </div>
    </section>

    <section class="pd-section-card">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Tedarikçi Bazlı Katalog Görünümü</h3>
                <p class="pd-section-subtitle">Her tedarikçi için kaynak, hazır ürün ve Abone Firma kataloğu sayıları daha kompakt görünür.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-table-wrap">
                <table class="pd-table pd-table-compact">
                    <thead>
                        <tr>
                            <th>Tedarikçi</th>
                            <th>Kaynak</th>
                            <th>Ön Kontrol</th>
                            <th>Hazır Ürün</th>
                            <th>Abone Firma Kataloğu</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($supplierRows as $row)
                            <tr>
                                <td><strong>{{ $row['supplier']->name }}</strong></td>
                                <td>{{ $row['source_count'] }}</td>
                                <td>{{ $row['preview_records'] }}</td>
                                <td>{{ $row['standard_products'] }}</td>
                                <td>{{ $row['tenant_catalog_products'] }}</td>
                                <td>
                                    <div class="pd-row-actions">
                                        <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light pd-btn-sm">Kaynakları Aç</a>
                                        <a href="{{ route('admin.super.tenant-supplier-access.index') }}" class="pd-btn pd-btn-light pd-btn-sm">Tedarikçi Erişimleri</a>
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
        <h3 class="pd-summary-title">Yayın Özeti</h3>

        <div class="pd-status-list">
            <div class="pd-status-row"><span>Abone Firma sayısı</span><span class="pd-badge pd-badge-blue">{{ $catalogOutput['active_tenants'] ?? 0 }}</span></div>
            <div class="pd-status-row"><span>Katalogda ürün</span><span class="pd-badge pd-badge-green">{{ $catalogOutput['tenant_open_products'] }}</span></div>
            <div class="pd-status-row"><span>Katalogda kapalı</span><span class="pd-badge pd-badge-gray">{{ $catalogOutput['tenant_closed_products'] }}</span></div>
            <div class="pd-status-row"><span>Eksik kayıt</span><span class="pd-badge pd-badge-amber">{{ $catalogOutput['projection_blocked_missing_category'] }}</span></div>
            <div class="pd-status-row"><span>İnceleme bekleyen</span><span class="pd-badge pd-badge-red">{{ $catalogOutput['warning_products'] }}</span></div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Günlük Aksiyonlar</h4>
            <div class="pd-summary-action-list">
                <a href="{{ route('admin.super.product-data-hub.sources.sync-reports') }}" class="pd-summary-action"><span>Ön Kontrol Yap</span><span class="pd-badge pd-badge-blue">Kontrol</span></a>
                <form action="{{ route('admin.super.product-data-hub.catalog-output.project-missing') }}" method="POST" onsubmit="return confirm('Seçili Abone Firma için eksik projection kayıtları tamamlanacak. Devam edilsin mi?');">
                    @csrf
                    <input type="hidden" name="tenant_id" value="{{ $selectedTenant?->id }}">
                    <button type="submit" class="pd-summary-action" @disabled(!$selectedTenant)><span>Eksik Kayıtları Tamamla</span><span class="pd-badge pd-badge-amber">Eksik</span></button>
                </form>
                <form action="{{ route('admin.super.product-data-hub.catalog-output.project-refresh') }}" method="POST" onsubmit="return confirm('Seçili Abone Firma için değişen projection kayıtları kataloğa yansıtılacak. Devam edilsin mi?');">
                    @csrf
                    <input type="hidden" name="tenant_id" value="{{ $selectedTenant?->id }}">
                    <button type="submit" class="pd-summary-action" @disabled(!$selectedTenant)><span>Değişen Ürünleri Kataloğa Yansıt</span><span class="pd-badge pd-badge-purple">Değişen</span></button>
                </form>
                <a href="{{ route('admin.super.tenant-supplier-access.index') }}" class="pd-summary-action"><span>Tedarikçi Erişimlerini Aç</span><span class="pd-badge pd-badge-green">Erişim</span></a>
            </div>
        </div>

        <div class="pd-side-note">Önizleme canlı veriyi gösterir; katalog ve teklif fiyatları projection yenileme sonrası güncellenir. Review-only rapor mutating işlem yerine kullanılmaz.</div>
    </div>
</div>
@endsection

@section('bottom_actions')
<div>
    <strong>Abone Katalog Yayını:</strong>
    <span class="pd-muted">Ön kontrol read-only kalır. Projection güncellemesi yalnız seçili Abone Firma context’iyle çalışır.</span>
</div>
<div class="pd-bottom-action-buttons">
    <a href="{{ route('admin.super.product-data-hub.sources.sync-reports') }}" class="pd-btn pd-btn-light">Senkron Raporları</a>
    <a href="{{ route('admin.super.tenant-supplier-access.index') }}" class="pd-btn pd-btn-light">Abone Firma Tedarikçi Erişimleri</a>
    @if($selectedTenantCatalogUrl)
        <a href="{{ $selectedTenantCatalogUrl }}" class="pd-btn pd-btn-warning">Katalog Ürünleri</a>
    @endif
    <form action="{{ route('admin.super.product-data-hub.catalog-output.project-refresh') }}" method="POST" onsubmit="return confirm('Seçili Abone Firma için projection kayıtları güncellenecek. Devam edilsin mi?');">
        @csrf
        <input type="hidden" name="tenant_id" value="{{ $selectedTenant?->id }}">
        <button type="submit" class="pd-btn pd-btn-primary" @disabled(!$selectedTenant)>Abone Katalog Güncelle</button>
    </form>
</div>
@endsection
