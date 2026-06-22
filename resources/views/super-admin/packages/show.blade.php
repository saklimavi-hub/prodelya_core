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
            <div class="pd-grid pd-grid-4">
                <div><div class="text-sm text-gray-600">Key</div><div class="font-medium">{{ $package->key }}</div></div>
                <div><div class="text-sm text-gray-600">Durum</div><div><span class="pd-badge {{ match($package->status){'active'=>'pd-badge-green','passive'=>'pd-badge-gray','planned'=>'pd-badge-amber','archived'=>'pd-badge-red',default=>'pd-badge-gray'} }}">{{ $package->safeStatusLabel() }}</span></div></div>
                <div><div class="text-sm text-gray-600">Aylık</div><div class="font-medium">{{ $package->formattedPrice('monthly') ?: '-' }}</div></div>
                <div><div class="text-sm text-gray-600">Yıllık</div><div class="font-medium">{{ $package->formattedPrice('yearly') ?: '-' }}</div></div>
                <div><div class="text-sm text-gray-600">Trial</div><div class="font-medium">{{ $package->trial_days !== null ? $package->trial_days . ' gün' : '-' }}</div></div>
                <div><div class="text-sm text-gray-600">Tenant Sayısı</div><div class="font-medium">{{ $package->tenants->count() }}</div></div>
            </div>
        </div>
    </section>

    <div class="pd-grid" style="grid-template-columns: minmax(0, 1fr); gap:16px;">
        <div class="pd-card">
            <div class="pd-card-header"><div><h3 class="pd-card-title">Modüller</h3><p class="pd-card-subtitle">Enabled module listesi ve durum etiketleri.</p></div></div>
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
            <div class="pd-card-header"><div><h3 class="pd-card-title">Feature’lar</h3><p class="pd-card-subtitle">Enabled feature listesi ve bağlı modül bilgisi.</p></div></div>
            <div class="pd-card-body">
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead><tr><th>Feature</th><th>Modül</th><th>Durum</th></tr></thead>
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
    </div>
</div>
@endsection
