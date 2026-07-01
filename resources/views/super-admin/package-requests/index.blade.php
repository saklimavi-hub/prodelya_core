@extends('layouts.prodelya-admin')

@section('title', 'Başvurular / Paket Talepleri')
@section('page_topbar_hidden', '1')

@php
    $statusClasses = [
        'new' => 'pd-badge-amber',
        'approved' => 'pd-badge-blue',
        'rejected' => 'pd-badge-gray',
        'completed' => 'pd-badge-green',
    ];
@endphp

@section('content')
<div class="pd-hub-family-shell">
    <div class="pd-request-hub-stack">
        <section class="pd-hero-card">
            <div class="pd-card-body">
                <div class="pd-hero-main">
                    <div class="pd-hero-copy">
                        <h1 class="pd-hero-title">Başvurular / Paket Talepleri</h1>
                        <p class="pd-hero-subtitle">Tenant yöneticilerden gelen paket değişim taleplerini Başvurular çatısı altında yönetin, önceliklendirin ve uygulama kararına hazırlayın.</p>
                    </div>
                </div>
            </div>
        </section>

        @include('super-admin.partials.request-hub-tabs')

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Kontrol Paneli</h3>
                    <p class="pd-section-subtitle">Tenant sonrası ticari talep akışını hızlı karar verecek şekilde özetleyin.</p>
                </div>
                <div class="pd-chip-group">
                    <a href="{{ route('admin.super.package-requests.index', ['status' => 'new']) }}" class="pd-chip {{ ($filters['status'] ?? '') === 'new' ? 'is-active' : '' }}">Yeni</a>
                    <a href="{{ route('admin.super.package-requests.index', ['status' => 'approved']) }}" class="pd-chip {{ ($filters['status'] ?? '') === 'approved' ? 'is-active' : '' }}">Onaylandı</a>
                    <a href="{{ route('admin.super.package-requests.index', ['status' => 'completed']) }}" class="pd-chip {{ ($filters['status'] ?? '') === 'completed' ? 'is-active' : '' }}">Tamamlandı</a>
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-mini-kpi-grid">
                    @foreach($summaryCards as $card)
                        <div class="pd-mini-kpi-card">
                            <div class="pd-mini-kpi-label">{{ $card['label'] }}</div>
                            <div class="pd-mini-kpi-value">{{ $card['value'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">Filtreler</h3>
                    <p class="pd-section-subtitle">Tenant, durum ve hedef paket kırılımında talepleri ayırın.</p>
                </div>
            </div>
            <div class="pd-section-body">
                <form method="GET" class="pd-grid pd-grid-4">
                    <div>
                        <label class="pd-label" for="search">Arama</label>
                        <input id="search" name="search" value="{{ $filters['search'] }}" class="pd-input" placeholder="Firma veya talep eden">
                    </div>
                    <div>
                        <label class="pd-label" for="status">Durum</label>
                        <select id="status" name="status" class="pd-input">
                            <option value="">Tümü</option>
                            @foreach($statusOptions as $key => $label)
                                <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="pd-label" for="package">İstenen Paket</label>
                        <select id="package" name="package" class="pd-input">
                            <option value="">Tümü</option>
                            @foreach($packages as $package)
                                <option value="{{ $package->key }}" @selected($filters['package'] === $package->key)>{{ $package->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pd-request-hub-form-actions">
                        <button type="submit" class="pd-btn pd-btn-primary">Filtrele</button>
                        <a href="{{ route('admin.super.package-requests.index') }}" class="pd-btn pd-btn-light">Sıfırla</a>
                    </div>
                </form>
            </div>
        </section>

        <section class="pd-section-card pd-section-card-soft-slate">
            <div class="pd-section-header">
                <div>
                    <h3 class="pd-section-title">1. Paket Talebi Listesi / Hızlı Önceliklendirme</h3>
                    <p class="pd-section-subtitle">Tenant yöneticilerden gelen paket değişim taleplerini karar önceliğine göre sıralayın.</p>
                </div>
                <div class="pd-request-list-header-copy">
                    Talebi, paket geçişini ve uygulama hazırlığını tek satırda karşılaştırın.
                </div>
            </div>
            <div class="pd-section-body">
                <div class="pd-request-list-shell">
                    <div class="pd-request-list-legend pd-request-list-grid-package">
                        <span>Durum</span>
                        <span>Tenant / Talep</span>
                        <span>Paket Geçişi</span>
                        <span>Talep Eden</span>
                        <span class="text-right">İşlem</span>
                    </div>

                    <div class="pd-request-row-list">
                        @forelse($requests as $item)
                            <article class="pd-request-row-card">
                                <div class="pd-request-row-grid pd-request-list-grid-package">
                                    <div class="pd-request-row-status">
                                        <span class="pd-badge {{ $statusClasses[$item->status] ?? 'pd-badge-gray' }}">{{ $statusOptions[$item->status] ?? $item->status }}</span>
                                        <div class="pd-request-row-time">{{ optional($item->created_at)->format('d.m.Y H:i') }}</div>
                                    </div>
                                    <div>
                                        <div class="pd-request-cell-title">{{ $item->tenant?->name ?: '-' }}</div>
                                        <div class="pd-request-cell-copy">{{ $item->tenant?->slug ?: 'Tenant kodu yok' }}</div>
                                        <div class="pd-request-cell-meta">{{ $item->request_note ?: 'Açıklama girilmemiş.' }}</div>
                                    </div>
                                    <div>
                                        <div class="pd-request-cell-title">{{ $item->currentPackage?->name ?? ($item->current_package_key ?: '-') }}</div>
                                        <div class="pd-request-cell-copy">{{ $item->requestedPackage?->name ?? $item->requested_package_key }}</div>
                                        @if($item->status === \App\Models\TenantPackageUpgradeRequest::STATUS_COMPLETED && $item->tenant)
                                            <div class="pd-request-cell-meta">
                                                <a href="{{ route('admin.super.tenants.show', $item->tenant) }}" class="text-blue-700 hover:underline">Abone Firma Kaydını Aç</a>
                                            </div>
                                        @else
                                            <div class="pd-request-cell-meta">Uygulama kararı detay ekranında verilir</div>
                                        @endif
                                    </div>
                                    <div>
                                        <div class="pd-request-cell-title">{{ $item->requester?->name ?: '-' }}</div>
                                        <div class="pd-request-cell-meta">{{ $item->requester?->email ?: '-' }}</div>
                                    </div>
                                    <div class="pd-request-actions">
                                        <a href="{{ route('admin.super.package-requests.show', $item) }}" class="pd-btn pd-btn-light">Detay</a>
                                    </div>
                                </div>
                            </article>
                        @empty
                            <div class="pd-empty-card">
                                <div class="text-sm text-gray-500">Henüz paket talebi yok.</div>
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="mt-4">{{ $requests->links() }}</div>
            </div>
        </section>
    </div>
</div>
@endsection

@section('side_summary')
    <div class="pd-decision-panel-stack">
        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Talep Karar Paneli</h3>

                <div class="pd-summary-info">
                    <div class="pd-summary-row">
                        <span>Yeni Talepler</span>
                        <span class="pd-badge pd-badge-amber">{{ $sideSummary['new_count'] }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Onay Bekleyen</span>
                        <span class="pd-badge pd-badge-blue">{{ $sideSummary['approved_count'] }}</span>
                    </div>
                    <div class="pd-summary-row">
                        <span>Filtre Durumu</span>
                        <span class="pd-badge pd-badge-blue">{{ $sideSummary['filtered_count'] }} kayıt</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Hızlı İşlemler</h3>
                <div class="pd-summary-action-list">
                    <a href="{{ route('admin.super.package-requests.index', ['status' => 'new']) }}" class="pd-summary-action">
                        <span>Yeni Talepler</span>
                        <span class="pd-badge pd-badge-amber">new</span>
                    </a>
                    <a href="{{ route('admin.super.package-requests.index', ['status' => 'approved']) }}" class="pd-summary-action">
                        <span>Onay Bekleyenler</span>
                        <span class="pd-badge pd-badge-blue">approved</span>
                    </a>
                    <a href="{{ route('admin.super.package-requests.index', ['status' => 'completed']) }}" class="pd-summary-action">
                        <span>Uygulananlar</span>
                        <span class="pd-badge pd-badge-green">completed</span>
                    </a>
                    <a href="{{ route('admin.super.package-requests.index', ['status' => 'rejected']) }}" class="pd-summary-action">
                        <span>Reddedilenler</span>
                        <span class="pd-badge pd-badge-gray">rejected</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-body">
                <h3 class="pd-summary-title">Operasyon Notu</h3>
                <div class="pd-note">
                    <p class="text-sm text-gray-600">Paket talebi ekranı tenant sonrası ticari talebi izler. Gerçek paket güncellemesi yalnız onaylanan talepte ve mevcut uygulama akışıyla yapılır.</p>
                    <div class="pd-summary-info mt-3">
                        <div class="pd-summary-row">
                            <span>En Çok İstenen Paket</span>
                            <span class="font-medium">{{ $sideSummary['top_package'] }}</span>
                        </div>
                        <div class="pd-summary-row">
                            <span>Tamamlananlar</span>
                            <span class="pd-badge pd-badge-green">{{ $sideSummary['completed_count'] }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
