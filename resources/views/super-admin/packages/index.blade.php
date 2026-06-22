@extends('layouts.prodelya-admin')

@section('title', 'Paketler')
@section('page_title', 'Paketler')
@section('page_subtitle', 'Merkezi satış paketlerini, varsayılan modülleri, feature setlerini ve limitleri yönetin.')

@section('page_actions')
<div class="flex gap-3">
    <a href="{{ route('admin.super.packages.create') }}" class="pd-btn pd-btn-primary">Yeni Paket</a>
</div>
@endsection

@section('content')
<div class="pd-hub-family-shell">
    <section class="pd-section-card pd-section-card-soft-blue">
        <div class="pd-section-body">
            <div class="pd-mini-grid">
                <div class="pd-mini-link-card"><div class="pd-mini-link-title">Aktif</div><div class="pd-mini-link-copy">{{ $stats['active'] }}</div></div>
                <div class="pd-mini-link-card"><div class="pd-mini-link-title">Pasif</div><div class="pd-mini-link-copy">{{ $stats['passive'] }}</div></div>
                <div class="pd-mini-link-card"><div class="pd-mini-link-title">Planlanan</div><div class="pd-mini-link-copy">{{ $stats['planned'] }}</div></div>
                <div class="pd-mini-link-card"><div class="pd-mini-link-title">Arşiv</div><div class="pd-mini-link-copy">{{ $stats['archived'] }}</div></div>
            </div>
        </div>
    </section>

    <section class="pd-section-card pd-section-card-soft-slate">
        <div class="pd-section-header">
            <div>
                <h3 class="pd-section-title">Paket Listesi</h3>
                <p class="pd-section-subtitle">Paket şablonları, modül kapsami ve temel ticari alanlar.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Paket</th>
                            <th>Key</th>
                            <th>Durum</th>
                            <th>Modül</th>
                            <th>Feature</th>
                            <th>Limit</th>
                            <th>Fiyat</th>
                            <th>Trial</th>
                            <th class="text-right">Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($packages as $package)
                            <tr>
                                <td>
                                    <div class="font-medium">{{ $package->name }}</div>
                                    <div class="text-sm text-gray-600">{{ $package->description }}</div>
                                </td>
                                <td><span class="pd-badge pd-badge-blue">{{ $package->key }}</span></td>
                                <td>
                                    <span class="pd-badge {{ match($package->status){'active'=>'pd-badge-green','passive'=>'pd-badge-gray','planned'=>'pd-badge-amber','archived'=>'pd-badge-red',default=>'pd-badge-gray'} }}">
                                        {{ $package->safeStatusLabel() }}
                                    </span>
                                </td>
                                <td>{{ $package->modules_count }}</td>
                                <td>{{ $package->features_count }}</td>
                                <td>{{ $package->limits_count }}</td>
                                <td>
                                    <div class="text-sm text-gray-900">{{ $package->formattedPrice('monthly') ?: '-' }}</div>
                                    <div class="text-sm text-gray-500">{{ $package->formattedPrice('yearly') ?: '-' }}</div>
                                </td>
                                <td>{{ $package->trial_days !== null ? $package->trial_days . ' gün' : '-' }}</td>
                                <td class="text-right">
                                    <a href="{{ route('admin.super.packages.show', $package) }}" class="pd-btn pd-btn-sm pd-btn-light">Aç</a>
                                    <a href="{{ route('admin.super.packages.edit', $package) }}" class="pd-btn pd-btn-sm pd-btn-primary">Düzenle</a>
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
