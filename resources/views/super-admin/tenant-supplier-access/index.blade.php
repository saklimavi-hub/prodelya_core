@extends('layouts.prodelya-admin')

@section('title', 'Tenant Tedarikçi Erişimi')
@section('page_title', 'Tenant Tedarikçi Erişimi')
@section('page_subtitle', 'Product Data Hub modülleri, feed limitleri ve aktif tedarikçi erişimlerini tenant bazında yönetin.')

@section('content')
<div class="pd-hub-family-shell">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Tenant Tedarikçi Erişimi</h1>
                    <p class="pd-hero-subtitle">Tenant bazında Product Data Hub modüllerini, feed limitlerini ve aktif tedarikçi erişimlerini daha kompakt görünümle yönetin.</p>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.product-data-hub.index') }}" class="pd-btn pd-btn-light">PD Hub Ana</a>
                    <a href="{{ route('admin.super.product-data-hub.sources.index') }}" class="pd-btn pd-btn-light">Global Kaynaklar</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-green">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Tenant Listesi</h3>
                <p class="pd-section-subtitle">Super Admin tarafında tenant modülleri ve tedarikçi erişim hakları.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Tenant</th>
                            <th>Paket</th>
                            <th>Product Data Hub</th>
                            <th>Aktif Tedarikçi</th>
                            <th>Feed Limit</th>
                            <th>Export İzni</th>
                            <th class="text-right">Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($tenants as $tenant)
                        @php
                            $modules = $tenant->modules->keyBy('module_key');
                            $productDataHubEnabled = (bool) optional($modules->get('product_data_hub'))->is_enabled;
                            $feedModule = $modules->get('supplier_feed');
                            $exportEnabled = (bool) optional($modules->get('export_web_feed'))->is_enabled;
                            $activeSupplierCount = $tenant->supplierAccesses->filter(fn ($access) => $access->isCurrentlyAccessible())->count();
                        @endphp
                        <tr>
                            <td>
                                <div class="font-medium">{{ $tenant->name }}</div>
                                <div class="text-sm text-gray-600">{{ $tenant->slug }}</div>
                            </td>
                            <td>{{ $tenant->package_key ?: 'Henüz yok' }}</td>
                            <td><span class="pd-badge {{ $productDataHubEnabled ? 'pd-badge-green' : 'pd-badge-gray' }}">{{ $productDataHubEnabled ? 'Açık' : 'Kapalı' }}</span></td>
                            <td>{{ $activeSupplierCount }}</td>
                            <td>{{ $feedModule?->limit_value ?? 'Henüz yok' }}</td>
                            <td><span class="pd-badge {{ $exportEnabled ? 'pd-badge-green' : 'pd-badge-gray' }}">{{ $exportEnabled ? 'Açık' : 'Kapalı' }}</span></td>
                            <td class="text-right"><a href="{{ route('admin.super.tenant-supplier-access.edit', $tenant) }}" class="pd-btn pd-btn-sm pd-btn-light">Düzenle</a></td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="text-center">Tanımlı tenant bulunamadı.</td>
                        </tr>
                        @endforelse
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
        <h3 class="pd-summary-title">Erişim Özeti</h3>
        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Modül Notları</h4>
            <div class="pd-summary-action-list">
                @foreach($moduleLabels as $label)
                    <span class="pd-summary-action"><span>{{ $label }}</span><span class="pd-badge pd-badge-blue">Modül</span></span>
                @endforeach
            </div>
        </div>
        <div class="pd-side-note">Permission katmanında `manage_tenant_supplier_access` ve `view_advanced_catalog` kontrolü eklenecek.</div>
    </div>
</div>
@endsection
