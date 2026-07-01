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
            <div class="pd-mini-kpi-strip">
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
                <p class="pd-section-subtitle">Paket şablonları, varsayılan limitler ve aktif Abone Firma dağılımı.</p>
            </div>
        </div>
        <div class="pd-section-body">
            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th>Paket</th>
                            <th>Anahtar / Kod</th>
                            <th>Durum</th>
                            <th>Kullanıcı</th>
                            <th>Ürün / Katalog</th>
                            <th>Tedarikçi</th>
                            <th>Sipariş</th>
                            <th>Aktif Abone Firma</th>
                            <th>Deneme</th>
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
                                <td>
                                    <div class="font-medium">{{ $package->users_limit_label }}</div>
                                    <div class="text-sm text-gray-500">Limit</div>
                                </td>
                                <td>
                                    <div class="font-medium">{{ $package->products_limit_label }}</div>
                                    <div class="text-sm text-gray-500">Katalog</div>
                                </td>
                                <td>
                                    <div class="font-medium">{{ $package->supplier_feeds_limit_label }}</div>
                                    <div class="text-sm text-gray-500">Feed</div>
                                </td>
                                <td>
                                    <div class="font-medium">{{ $package->orders_limit_label }}</div>
                                    <div class="text-sm text-gray-500">Sipariş</div>
                                </td>
                                <td>
                                    <div class="font-medium">{{ $package->active_tenants_count }}</div>
                                    <div class="text-sm text-gray-500">{{ $package->trial_tenants_count }} deneme</div>
                                </td>
                                <td>
                                    @if($package->trial_days !== null)
                                        <div class="font-medium">{{ $package->trial_days }} gün</div>
                                    @else
                                        <div class="font-medium">Takip edilmiyor</div>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <div class="pd-row-actions">
                                        <a href="{{ route('admin.super.packages.show', $package) }}" class="pd-btn pd-btn-sm pd-btn-light">Aç</a>
                                        <a href="{{ route('admin.super.packages.edit', $package) }}" class="pd-btn pd-btn-sm pd-btn-primary">Düzenle</a>
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
