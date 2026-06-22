@extends('layouts.prodelya-admin')

@section('title', 'Tenant Yönetimi')

@section('content')
<div class="pd-hub-family-shell">
    <section class="pd-hero-card">
        <div class="pd-card-body">
            <div class="pd-hero-main">
                <div class="pd-hero-copy">
                    <h1 class="pd-hero-title">Tenant Listesi</h1>
                    <p class="pd-hero-subtitle">Sistemdeki tüm tenant firmaların listesini, paket durumunu ve temel yönetim aksiyonlarını bu ekrandan izleyin.</p>
                </div>
                <div class="pd-hero-actions">
                    <a href="{{ route('admin.super.tenants.create') }}" class="pd-btn pd-btn-primary">Yeni Tenant</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Tenant Yönetimi</h3>
                <p class="pd-section-subtitle">Boş alanı azaltılmış daha kompakt tenant görünümü.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Firma Adı</th>
                            <th>Subdomain</th>
                            <th>Paket</th>
                            <th>Durum</th>
                            <th>Dil</th>
                            <th>Para</th>
                            <th class="text-right">Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($tenants as $tenant)
                        <tr>
                            <td>
                                <div class="font-medium">{{ $tenant->name }}</div>
                                <div class="text-sm text-gray-500">{{ $tenant->legal_name }}</div>
                            </td>
                            <td>
                                <div class="text-sm text-gray-900">{{ $tenant->panel_subdomain }}</div>
                                @if($tenant->custom_domain)
                                    <div class="text-sm text-gray-500">{{ $tenant->custom_domain }}</div>
                                @endif
                            </td>
                            <td>
                                <span class="pd-badge pd-badge-blue">{{ $tenant->package_key ?: 'Core' }}</span>
                                @if($tenant->package)
                                    <div class="text-sm text-gray-500" style="margin-top: 6px;">{{ $tenant->package->name }}</div>
                                @endif
                            </td>
                            <td>
                                @switch($tenant->status)
                                    @case('active')
                                        <span class="pd-badge pd-badge-green">Aktif</span>
                                        @break
                                    @case('trial')
                                        <span class="pd-badge pd-badge-blue">Trial</span>
                                        @break
                                    @case('inactive')
                                        <span class="pd-badge pd-badge-gray">Pasif</span>
                                        @break
                                    @case('suspended')
                                        <span class="pd-badge pd-badge-red">Askıya Alındı</span>
                                        @break
                                    @default
                                        <span class="pd-badge pd-badge-gray">{{ $tenant->status }}</span>
                                @endswitch
                            </td>
                            <td>{{ $tenant->default_locale }}</td>
                            <td>{{ $tenant->default_currency }}</td>
                            <td class="text-right">
                                <a href="{{ route('admin.super.tenants.show', $tenant) }}" class="pd-btn pd-btn-sm pd-btn-light">Aç</a>
                                <a href="{{ route('admin.super.tenants.edit', $tenant) }}" class="pd-btn pd-btn-sm pd-btn-primary">Düzenle</a>
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
        <h3 class="pd-summary-title">Tenant Özeti</h3>
        <div class="pd-status-list">
            <div class="pd-status-row"><span>Toplam Tenant</span><strong>{{ $tenants->count() }}</strong></div>
            <div class="pd-status-row"><span>Aktif</span><strong>{{ $tenants->where('status', 'active')->count() }}</strong></div>
            <div class="pd-status-row"><span>Pasif</span><strong>{{ $tenants->where('status', 'inactive')->count() }}</strong></div>
            <div class="pd-status-row"><span>Askıda</span><strong>{{ $tenants->where('status', 'suspended')->count() }}</strong></div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Hızlı Filtreler</h4>
            <div class="pd-summary-action-list">
                <span class="pd-summary-action"><span>Tümü</span><span class="pd-badge pd-badge-blue">Genel</span></span>
                <span class="pd-summary-action"><span>Sadece Aktifler</span><span class="pd-badge pd-badge-green">Aktif</span></span>
                <span class="pd-summary-action"><span>Sadece Pasifler</span><span class="pd-badge pd-badge-gray">Pasif</span></span>
                <span class="pd-summary-action"><span>Son 30 Gün</span><span class="pd-badge pd-badge-amber">Yeni</span></span>
            </div>
        </div>

        <div class="pd-summary-section">
            <h4 class="pd-summary-section-title">Dışa Aktarım</h4>
            <div class="pd-summary-action-list">
                <button class="pd-summary-action" type="button"><span>Excel'e Aktar</span><span class="pd-badge pd-badge-green">XLSX</span></button>
                <button class="pd-summary-action" type="button"><span>PDF'e Aktar</span><span class="pd-badge pd-badge-blue">PDF</span></button>
            </div>
        </div>
    </div>
</div>
@endsection
