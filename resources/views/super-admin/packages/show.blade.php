@extends('layouts.prodelya-admin')

@section('title', $package->name)
@section('page_title', $package->name)
@section('page_subtitle', 'Paket modülleri, feature seti ve kullanım limitleri özeti.')

@section('page_actions')
<div class="flex gap-3">
    <a href="{{ route('admin.super.packages.edit', $package) }}" class="pd-btn pd-btn-primary">Düzenle</a>
    <a href="{{ route('admin.super.packages.index') }}" class="pd-btn pd-btn-light">Listeye Dön</a>
</div>
@endsection

@section('content')
<div class="pd-hub-family-shell">
    <section class="pd-section-card pd-section-card-soft-purple">
        <div class="pd-section-body">
            <div class="pd-kpi-strip">
                <div class="pd-metric-card pd-metric-card-soft-blue"><div class="pd-metric-card-label">Anahtar / Kod</div><div class="pd-metric-card-value">{{ $package->key }}</div><div class="pd-metric-card-note">Paket kimliği</div></div>
                <div class="pd-metric-card pd-metric-card-soft-green"><div class="pd-metric-card-label">Durum</div><div class="pd-metric-card-value">{{ $package->safeStatusLabel() }}</div><div class="pd-metric-card-note">Yaşam döngüsü</div></div>
                <div class="pd-metric-card pd-metric-card-soft-purple"><div class="pd-metric-card-label">Aylık / Yıllık</div><div class="pd-metric-card-value">{{ $package->formattedPrice('monthly') ?: '-' }}</div><div class="pd-metric-card-note">{{ $package->formattedPrice('yearly') ?: 'Yıllık fiyat yok' }}</div></div>
                <div class="pd-metric-card pd-metric-card-soft-slate"><div class="pd-metric-card-label">Kullanan Abone Firma</div><div class="pd-metric-card-value">{{ count($tenantRows) }}</div><div class="pd-metric-card-note">{{ $overrideTenantCount }} override kullanıyor</div></div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Genel Bilgiler</h3>
                <p class="pd-section-subtitle">Paket açıklaması, notları ve canlı operasyon için genel çerçeve.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-grid pd-grid-3">
                <div>
                    <div class="text-sm text-gray-600">Açıklama</div>
                    <div class="font-medium">{{ $package->description ?: 'Veri yok' }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-600">Varsayılan Süreç Derinliği</div>
                    <div class="font-medium">{{ $package->processDepthLabel() }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-600">Notlar</div>
                    <div class="font-medium">{{ $package->notes ?: 'Fiyatlandırma / ödeme entegrasyonu sonraki faz' }}</div>
                </div>
            </div>
        </div>
    </section>

    <div class="pd-stack-md">
        <div class="pd-card">
            <div class="pd-card-header"><div><h3 class="pd-card-title">Dahil Modüller</h3><p class="pd-card-subtitle">Paket varsayılanı olarak açılan modüller ve durum etiketleri.</p></div></div>
            <div class="pd-card-body">
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead><tr><th>Modül</th><th>Kategori</th><th>Durum</th><th>Açıklama</th></tr></thead>
                        <tbody>
                            @foreach($moduleCatalog as $module)
                                @if($module['enabled'])
                                    <tr>
                                        <td><div class="font-medium">{{ $module['label'] }}</div><div class="text-sm text-gray-500">{{ $module['key'] }}</div></td>
                                        <td>{{ $module['category'] }}</td>
                                        <td><span class="pd-badge {{ $module['locked'] ? 'pd-badge-blue' : ($module['status'] === 'active' ? 'pd-badge-green' : 'pd-badge-amber') }}">{{ $module['locked'] ? 'Core' : ucfirst($module['status']) }}</span></td>
                                        <td>{{ $module['description'] }}</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-header"><div><h3 class="pd-card-title">Dahil Özellikler</h3><p class="pd-card-subtitle">Paketin açtığı alt yetenekler ve bağlı modül bilgisi.</p></div></div>
            <div class="pd-card-body">
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead><tr><th>Özellik</th><th>Modül</th><th>Durum</th></tr></thead>
                        <tbody>
                            @foreach($featureCatalog as $feature)
                                @if($feature['enabled'])
                                    <tr>
                                        <td><div class="font-medium">{{ $feature['label'] }}</div><div class="text-sm text-gray-500">{{ $feature['key'] }}</div></td>
                                        <td>{{ $feature['module_label'] }}</td>
                                        <td><span class="pd-badge {{ $feature['status'] === 'active' ? 'pd-badge-green' : 'pd-badge-amber' }}">{{ ucfirst($feature['status']) }}</span></td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-header"><div><h3 class="pd-card-title">Limitler</h3><p class="pd-card-subtitle">Kullanım limitleri ve unlimited alanlar.</p></div></div>
            <div class="pd-card-body">
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead><tr><th>Alan</th><th>Limit</th><th>Not</th></tr></thead>
                        <tbody>
                            @foreach($limitRows as $limit)
                                <tr>
                                    <td>{{ $limit['label'] }}</td>
                                    <td><span class="pd-badge {{ $limit['is_unlimited'] ? 'pd-badge-blue' : 'pd-badge-green' }}">{{ $limit['is_unlimited'] ? 'Limitsiz' : ($limit['limit_value'] ?? '-') }}</span></td>
                                    <td>{{ $limit['notes'] ?: '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-header"><div><h3 class="pd-card-title">Bu Paketi Kullanan Abone Firmalar</h3><p class="pd-card-subtitle">Paket kullanan Abone Firmalar ve override görünürlüğü.</p></div></div>
            <div class="pd-card-body">
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead><tr><th>Abone Firma</th><th>Panel</th><th>Durum</th><th>Override</th></tr></thead>
                        <tbody>
                            @forelse($tenantRows as $tenant)
                                <tr>
                                    <td>
                                        <div class="font-medium">{{ $tenant['name'] }}</div>
                                        <div class="text-sm text-gray-500">{{ $tenant['slug'] }}</div>
                                    </td>
                                    <td>{{ $tenant['panel_subdomain'] ?: 'Panel adresi eksik' }}</td>
                                    <td>
                                        <span class="pd-badge {{ match($tenant['status']) {'active' => 'pd-badge-green', 'trial' => 'pd-badge-blue', 'passive' => 'pd-badge-gray', 'suspended' => 'pd-badge-red', 'expired' => 'pd-badge-amber', default => 'pd-badge-gray'} }}">
                                            {{ match($tenant['status']) {'active' => 'Aktif', 'trial' => 'Deneme', 'passive' => 'Pasif', 'suspended' => 'Askıda', 'expired' => 'Süresi Dolmuş', default => $tenant['status']} }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="pd-badge {{ $tenant['has_override'] ? 'pd-badge-amber' : 'pd-badge-green' }}">
                                            {{ $tenant['has_override'] ? 'Tenant override var' : 'Paket varsayılanı' }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-sm text-gray-500">Bu paketi kullanan Abone Firma yok.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
