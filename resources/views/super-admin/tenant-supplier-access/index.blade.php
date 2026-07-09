@extends('layouts.prodelya-admin')

@section('title', 'Abone Firma Tedarikçi Erişimleri')
@section('page_title', 'Abone Firma Tedarikçi Erişimleri')
@section('page_subtitle', 'Bir tedarikçinin satışa uygun ürünlerinin Abone Firma ürün listesi ve teklif aramasında otomatik görünebilmesi için erişim izinlerini buradan yönetin.')

@section('content')
<div class="pd-hub-family-shell pd-product-hub">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Abone Firma Tedarikçi Erişimleri</h1>
                    <p class="pd-hero-subtitle">Bir tedarikçinin ürünlerinin Abone Firma ürün listesinde ve teklif aramasında otomatik görünebilmesi için tedarikçi erişimi aktif, katalog görünürlüğü açık ve teklif kullanımı izinli olmalıdır. Ekstra “teklife aktar” adımı yoktur.</p>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.product-data-hub.index') }}" class="pd-btn pd-btn-light">Ürün Veri Merkezi</a>
                    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Kaynak ve Ön Kontrol</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-green">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Abone Firma Listesi</h3>
                <p class="pd-section-subtitle">Otomatik ürün görünürlüğü için ön koşul olan erişim ve görünürlük izinlerini daha sade tabloda izleyin.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-note mb-4 pd-product-hub__auto-note">Tedarikçi erişimi açık ve ürün satışa uygunsa senkronizasyon sonrası ürünler Abone Firma ürün listesinde ve teklif aramasında otomatik görünür. Eski teklif snapshot kayıtları ayrıca korunur.</div>
            <div class="pd-table-wrap">
                <table class="pd-table pd-table-compact">
                    <thead>
                        <tr>
                            <th>Abone Firma</th>
                            <th>Tedarikçi</th>
                            <th>Aktif</th>
                            <th>Katalogda Görünsün</th>
                            <th>Teklifte Kullanılsın</th>
                            <th>Satınalma / Talep</th>
                            <th>Son katalog durumu</th>
                            <th class="text-right">Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($tenants as $tenant)
                        @php
                            $modules = $tenant->modules->keyBy('module_key');
                            $productDataHubEnabled = (bool) optional($modules->get('product_data_hub'))->is_enabled;
                            $activeSupplierCount = $tenant->supplierAccesses->filter(fn ($access) => $access->isCurrentlyAccessible())->count();
                            $catalogVisibleCount = $tenant->supplierAccesses->where('visible_in_catalog', true)->count();
                            $quoteVisibleCount = $tenant->supplierAccesses->where('can_use_in_quotes', true)->count();
                            $purchaseEnabledCount = $tenant->supplierAccesses->where('can_request_purchase', true)->count();
                        @endphp
                        <tr>
                            <td>
                                <div class="font-medium">{{ $tenant->name }}</div>
                                <div class="text-sm text-gray-600">{{ $tenant->slug }}</div>
                            </td>
                            <td>{{ $activeSupplierCount }}</td>
                            <td><span class="pd-badge {{ $productDataHubEnabled ? 'pd-badge-green' : 'pd-badge-gray' }}">{{ $productDataHubEnabled ? 'Açık' : 'Kapalı' }}</span></td>
                            <td>{{ $catalogVisibleCount }}</td>
                            <td>{{ $quoteVisibleCount }}</td>
                            <td>{{ $purchaseEnabledCount }}</td>
                            <td>
                                @if($productDataHubEnabled && $activeSupplierCount > 0)
                                    <span class="pd-badge pd-badge-green">Katalog yayını hazır</span>
                                @else
                                    <span class="pd-badge pd-badge-amber">Erişim kontrolü gerekli</span>
                                @endif
                            </td>
                            <td class="text-right"><a href="{{ route('admin.super.tenant-supplier-access.edit', $tenant) }}" class="pd-btn pd-btn-sm pd-btn-light">Düzenle</a></td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="text-center">Tanımlı Abone Firma bulunamadı.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-body">
            <div class="pd-mini-kpi-strip">
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Tenant</div><div class="pd-mini-kpi-value">{{ $tenants->count() }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">PD Hub Açık</div><div class="pd-mini-kpi-value">{{ $tenants->filter(fn ($tenant) => (bool) optional($tenant->modules->keyBy('module_key')->get('product_data_hub'))->is_enabled)->count() }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Erişimli Tenant</div><div class="pd-mini-kpi-value">{{ $tenants->filter(fn ($tenant) => $tenant->supplierAccesses->filter(fn ($access) => $access->isCurrentlyAccessible())->count() > 0)->count() }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Katalog Açık</div><div class="pd-mini-kpi-value">{{ $tenants->filter(fn ($tenant) => $tenant->supplierAccesses->where('visible_in_catalog', true)->count() > 0)->count() }}</div></div>
                <div class="pd-mini-kpi-card"><div class="pd-mini-kpi-label">Teklif Açık</div><div class="pd-mini-kpi-value">{{ $tenants->filter(fn ($tenant) => $tenant->supplierAccesses->where('can_use_in_quotes', true)->count() > 0)->count() }}</div></div>
            </div>
        </div>
    </section>
</div>
@endsection

@section('side_summary')
<div class="pd-side-summary">
    <div class="pd-card-body">
        <h3 class="pd-summary-title">Erişim Özeti</h3>
        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Modül Notları</h4>
            <div class="pd-summary-action-list">
                @foreach($moduleLabels as $label)
                    <span class="pd-summary-action"><span>{{ $label }}</span><span class="pd-badge pd-badge-blue">Modül</span></span>
                @endforeach
            </div>
        </div>
        <div class="pd-side-note">Bu ekran otomatik ürün görünürlüğü için ön koşul olan erişim kurallarını gösterir. Ürünler görünmüyorsa önce bu izinleri kontrol edin.</div>
    </div>
</div>
@endsection
